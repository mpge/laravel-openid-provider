<?php

namespace Mpge\OpenIdProvider\Authorization;

use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Mpge\OpenIdProvider\Exceptions\AuthorizationException;
use Mpge\OpenIdProvider\Exceptions\UntrustedRedirectException;
use Mpge\OpenIdProvider\Support\ClientLookup;

/**
 * Validates an authorization request before anything else happens.
 *
 * The client and redirect_uri are checked first. Until both are verified,
 * every error is an {@see UntrustedRedirectException} (shown as a page).
 * After that, errors are {@see AuthorizationException}s that are returned to
 * the client's redirect_uri together with the state.
 */
class AuthorizationRequestValidator
{
    /** Prompt values this provider understands. */
    public const SUPPORTED_PROMPTS = ['none', 'login', 'consent', 'select_account'];

    public function __construct(private readonly ClientLookup $clients)
    {
    }

    /**
     * @param  array<string, mixed>  $query  The decoded query parameters.
     * @param  string  $rawQuery  The raw query string, used to detect repeated parameters.
     *
     * @throws UntrustedRedirectException
     * @throws AuthorizationException
     */
    public function validate(array $query, string $rawQuery = ''): AuthorizationParameters
    {
        $repeated = $this->repeatedParameters($rawQuery);

        foreach (['client_id', 'redirect_uri'] as $name) {
            if (in_array($name, $repeated, true)) {
                throw new UntrustedRedirectException('invalid_request', "The {$name} parameter was sent more than once.");
            }
        }

        $clientId = $this->string($query, 'client_id');

        if ($clientId === null) {
            throw new UntrustedRedirectException('invalid_request', 'The client_id parameter is missing.');
        }

        $client = $this->clients->active($clientId)
            ?? throw new UntrustedRedirectException('invalid_client', 'The client is unknown or has been revoked.');

        $redirectUri = $this->string($query, 'redirect_uri');

        if ($redirectUri === null) {
            throw new UntrustedRedirectException('invalid_request', 'The redirect_uri parameter is missing.');
        }

        // Exact string comparison only: no prefix, wildcard or port leniency.
        if (! $this->isRegisteredRedirectUri($client, $redirectUri)) {
            throw new UntrustedRedirectException('invalid_request', 'The redirect_uri is not registered for this client.');
        }

        // From here on the redirect_uri is trusted.
        $state = $this->rawString($query, 'state');
        $fail = fn (string $error, string $description): AuthorizationException => new AuthorizationException($error, $description, $redirectUri, $state);

        if ($repeated !== []) {
            throw $fail('invalid_request', 'Parameters must not be sent more than once: '.implode(', ', $repeated).'.');
        }

        foreach ($query as $name => $value) {
            if (! is_string($value)) {
                throw $fail('invalid_request', "The {$name} parameter must be a single value.");
            }
        }

        if (isset($query['request'])) {
            throw $fail('request_not_supported', 'Request objects are not supported.');
        }

        if (isset($query['request_uri'])) {
            throw $fail('request_uri_not_supported', 'The request_uri parameter is not supported.');
        }

        if (isset($query['registration'])) {
            throw $fail('registration_not_supported', 'The registration parameter is not supported.');
        }

        $responseType = $this->string($query, 'response_type');

        if ($responseType === null) {
            throw $fail('invalid_request', 'The response_type parameter is missing.');
        }

        if ($responseType !== 'code') {
            throw $fail('unsupported_response_type', 'Only the authorization code flow (response_type=code) is supported.');
        }

        $responseMode = $this->string($query, 'response_mode');

        if ($responseMode !== null && $responseMode !== 'query') {
            throw $fail('invalid_request', 'Only response_mode=query is supported.');
        }

        if (! $client->hasGrantType('authorization_code')) {
            throw $fail('unauthorized_client', 'This client may not use the authorization code flow.');
        }

        $prompt = $this->prompt($query, $fail);
        $maxAge = $this->maxAge($query, $fail);
        [$codeChallenge, $codeChallengeMethod] = $this->pkce($query, $client, $fail);
        $scopes = $this->scopes($query, $fail);

        return new AuthorizationParameters(
            clientId: (string) $client->getKey(),
            clientName: (string) $client->name,
            redirectUri: $redirectUri,
            scopes: $scopes,
            state: $state,
            nonce: $this->rawString($query, 'nonce'),
            prompt: $prompt,
            maxAge: $maxAge,
            codeChallenge: $codeChallenge,
            codeChallengeMethod: $codeChallengeMethod,
        );
    }

    private function isRegisteredRedirectUri(Client $client, string $redirectUri): bool
    {
        foreach ((array) $client->redirect_uris as $registered) {
            if (is_string($registered) && $registered !== '' && hash_equals($registered, $redirectUri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  callable(string, string): AuthorizationException  $fail
     * @return list<string>
     */
    private function prompt(array $query, callable $fail): array
    {
        $raw = $this->string($query, 'prompt');

        if ($raw === null) {
            return [];
        }

        $values = array_values(array_unique(array_filter(explode(' ', $raw), fn (string $value): bool => $value !== '')));

        foreach ($values as $value) {
            if (! in_array($value, self::SUPPORTED_PROMPTS, true)) {
                throw $fail('invalid_request', "The prompt value [{$value}] is not supported.");
            }
        }

        if (in_array('none', $values, true) && count($values) > 1) {
            throw $fail('invalid_request', 'prompt=none cannot be combined with other prompt values.');
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  callable(string, string): AuthorizationException  $fail
     */
    private function maxAge(array $query, callable $fail): ?int
    {
        $raw = $this->string($query, 'max_age');

        if ($raw === null) {
            return null;
        }

        if (preg_match('/^\d{1,10}$/', $raw) !== 1) {
            throw $fail('invalid_request', 'max_age must be a non-negative integer.');
        }

        return (int) $raw;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  callable(string, string): AuthorizationException  $fail
     * @return array{0: string|null, 1: string|null}
     */
    private function pkce(array $query, Client $client, callable $fail): array
    {
        $challenge = $this->rawString($query, 'code_challenge');
        $method = $this->rawString($query, 'code_challenge_method');

        if ($challenge === null) {
            if ($method !== null) {
                throw $fail('invalid_request', 'code_challenge_method was sent without a code_challenge.');
            }

            if (! $client->confidential()) {
                throw $fail('invalid_request', 'PKCE (code_challenge with S256) is required for public clients.');
            }

            if (config('openid-provider.pkce.required_for_confidential_clients', false) === true) {
                throw $fail('invalid_request', 'PKCE (code_challenge with S256) is required.');
            }

            return [null, null];
        }

        if ($method !== 'S256') {
            throw $fail('invalid_request', 'Only the S256 code_challenge_method is supported.');
        }

        if (preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $challenge) !== 1) {
            throw $fail('invalid_request', 'The code_challenge is malformed.');
        }

        return [$challenge, 'S256'];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  callable(string, string): AuthorizationException  $fail
     * @return list<string>
     */
    private function scopes(array $query, callable $fail): array
    {
        $raw = $this->string($query, 'scope');

        $requested = $raw === null
            ? array_values(array_map('strval', (array) config('openid-provider.default_scopes', [])))
            : array_values(array_unique(array_filter(explode(' ', $raw), fn (string $scope): bool => $scope !== '')));

        $known = array_values(array_filter($requested, fn (string $scope): bool => Passport::hasScope($scope)));

        // OpenID Connect Core 3.1.2.1: scope values that are not understood
        // SHOULD be ignored. Plain OAuth 2.0 requests keep the strict
        // invalid_scope behaviour. Ignored scopes are never granted.
        if (! in_array('openid', $requested, true) && count($known) !== count($requested)) {
            $unknown = array_values(array_diff($requested, $known));

            throw $fail('invalid_scope', 'Unknown scope: '.$unknown[0].'.');
        }

        return $known;
    }

    /**
     * A non-empty string parameter, or null.
     *
     * @param  array<string, mixed>  $query
     */
    private function string(array $query, string $name): ?string
    {
        $value = $query[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A string parameter exactly as sent (only empty values become null).
     *
     * @param  array<string, mixed>  $query
     */
    private function rawString(array $query, string $name): ?string
    {
        return $this->string($query, $name);
    }

    /**
     * Names of parameters that appear more than once in the raw query.
     *
     * @return list<string>
     */
    private function repeatedParameters(string $rawQuery): array
    {
        if ($rawQuery === '') {
            return [];
        }

        $seen = [];
        $repeated = [];

        foreach (explode('&', $rawQuery) as $pair) {
            if ($pair === '') {
                continue;
            }

            $name = urldecode(explode('=', $pair, 2)[0]);

            if (isset($seen[$name])) {
                $repeated[$name] = true;
            }

            $seen[$name] = true;
        }

        return array_keys($repeated);
    }
}
