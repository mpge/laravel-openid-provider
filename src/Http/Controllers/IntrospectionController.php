<?php

namespace Mpge\OpenIdProvider\Http\Controllers;

use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Laravel\Passport\Passport;
use Mpge\OpenIdProvider\Claims\ClaimsResolver;
use Mpge\OpenIdProvider\Support\AccessTokenValidator;
use Mpge\OpenIdProvider\Support\ClientAuthenticator;
use Mpge\OpenIdProvider\Support\ClientLookup;
use Mpge\OpenIdProvider\Support\Issuer;
use Mpge\OpenIdProvider\Support\UserResolver;
use Mpge\OpenIdProvider\Tokens\EncryptedPayloadReader;
use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;

/**
 * The token introspection endpoint (RFC 7662). Registered only when
 * openid-provider.introspection.enabled is true.
 *
 * Only confidential clients can call it. A client sees its own tokens; a
 * resource server listed in introspection.allowed_clients also sees the
 * tokens of the clients listed for it. Every other token, and every token
 * that is unknown, expired, revoked or belongs to a revoked client or a
 * user that no longer exists, is reported as {"active": false}, so the
 * endpoint reveals nothing about tokens the caller may not see.
 */
class IntrospectionController
{
    public function __construct(
        private readonly ClientAuthenticator $clients,
        private readonly ClientLookup $clientLookup,
        private readonly AccessTokenValidator $accessTokens,
        private readonly EncryptedPayloadReader $payloads,
        private readonly UserResolver $users,
        private readonly ClaimsResolver $claims,
    ) {}

    /**
     * POST /oauth/introspect
     */
    public function __invoke(ServerRequestInterface $request): JsonResponse
    {
        if ($this->clients->hasConflictingCredentials($request)) {
            return $this->error('invalid_request', 'Use exactly one client authentication method.', 400);
        }

        $client = $this->clients->authenticate($request);

        if ($client === null || ! $client->isConfidential()) {
            $headers = $this->clients->usedBasicAuthentication($request)
                ? ['WWW-Authenticate' => 'Basic realm="OAuth"']
                : [];

            return $this->error('invalid_client', 'Client authentication failed.', 401, $headers);
        }

        $body = (array) $request->getParsedBody();
        $token = $body['token'] ?? null;

        if (! is_string($token) || $token === '') {
            return $this->error('invalid_request', 'The token parameter is missing.', 400);
        }

        $caller = $client->getIdentifier();

        // token_type_hint only decides which lookup runs first (RFC 7662 2.1).
        $result = ($body['token_type_hint'] ?? null) === 'refresh_token'
            ? $this->refreshToken($token, $caller) ?? $this->accessToken($token, $caller)
            : $this->accessToken($token, $caller) ?? $this->refreshToken($token, $caller);

        return $this->respond($result ?? ['active' => false]);
    }

    /**
     * The introspection response for an access token, null when the value
     * is not a valid access token, or inactive when the caller may not see it.
     *
     * @return array<string, mixed>|null
     */
    private function accessToken(#[SensitiveParameter] string $token, string $caller): ?array
    {
        $attributes = $this->accessTokens->validate($token);

        if ($attributes === null) {
            return null;
        }

        if (! $this->mayIntrospect($caller, $attributes['client_id'])) {
            return ['active' => false];
        }

        $record = Passport::token()->newQuery()->find($attributes['token_id']);

        if ($record === null) {
            return ['active' => false];
        }

        $response = [
            'active' => true,
            'scope' => implode(' ', $attributes['scopes']),
            'client_id' => $attributes['client_id'],
            'token_type' => 'Bearer',
            'exp' => $this->timestamp($record->getAttribute('expires_at')),
            'iat' => $this->timestamp($record->getAttribute('created_at')),
            'aud' => $attributes['client_id'],
            'iss' => Issuer::identifier(),
        ];

        if ($attributes['user_id'] !== null) {
            $user = $this->users->find($attributes['user_id']);

            // A token whose user no longer exists is not active (fail closed).
            if ($user === null) {
                return ['active' => false];
            }

            $response['sub'] = $this->claims->subject($user);
        }

        return array_filter($response, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The introspection response for a refresh token, null when the value
     * is not a refresh token, or inactive when it is not usable or the caller
     * may not see it.
     *
     * @return array<string, mixed>|null
     */
    private function refreshToken(#[SensitiveParameter] string $token, string $caller): ?array
    {
        $payload = $this->payloads->refreshToken($token);

        if ($payload === null) {
            return null;
        }

        if (! $this->mayIntrospect($caller, $payload['client_id']) || $this->clientLookup->active($payload['client_id']) === null) {
            return ['active' => false];
        }

        $record = Passport::refreshToken()->newQuery()->find($payload['refresh_token_id']);
        $expires = $record === null ? null : $this->timestamp($record->getAttribute('expires_at'));

        if ($record === null || $record->getAttribute('revoked') || $expires === null || $expires <= time()) {
            return ['active' => false];
        }

        $response = [
            'active' => true,
            'client_id' => $payload['client_id'],
            'token_type' => 'refresh_token',
            'exp' => $expires,
            'iss' => Issuer::identifier(),
        ];

        if ($payload['user_id'] !== null) {
            $user = $this->users->find($payload['user_id']);

            if ($user === null) {
                return ['active' => false];
            }

            $response['sub'] = $this->claims->subject($user);
        }

        $accessToken = Passport::token()->newQuery()->find($payload['access_token_id']);
        $scopes = $accessToken?->getAttribute('scopes');

        if (is_array($scopes) && $scopes !== []) {
            $response['scope'] = implode(' ', array_map('strval', $scopes));
        }

        return $response;
    }

    /**
     * Whether the caller may see tokens issued to the owner client.
     */
    private function mayIntrospect(string $caller, string $owner): bool
    {
        if (hash_equals($owner, $caller)) {
            return true;
        }

        // Read the map directly: client IDs are not split on dots.
        $map = config('openid-provider.introspection.allowed_clients', []);
        $allowed = is_array($map) ? ($map[$caller] ?? null) : null;

        return is_array($allowed) && in_array($owner, array_map('strval', $allowed), true);
    }

    private function timestamp(mixed $value): ?int
    {
        return $value instanceof DateTimeInterface ? $value->getTimestamp() : null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function respond(array $body): JsonResponse
    {
        return new JsonResponse($body, 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function error(string $error, string $description, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse(
            ['error' => $error, 'error_description' => $description],
            $status,
            $headers + ['Cache-Control' => 'no-store'],
        );
    }
}
