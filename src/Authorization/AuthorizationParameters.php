<?php

namespace Mpge\OpenIdProvider\Authorization;

/**
 * A validated authorization request.
 *
 * Only built by {@see AuthorizationRequestValidator}; every field has been
 * checked against the client registration and the provider's policy.
 */
final class AuthorizationParameters
{
    /**
     * @param  list<string>  $scopes  Granted-if-approved scopes (unknown scopes removed).
     * @param  list<string>  $prompt  The prompt values, e.g. ['login', 'consent'].
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientName,
        public readonly string $redirectUri,
        public readonly array $scopes,
        public readonly ?string $state,
        public readonly ?string $nonce,
        public readonly array $prompt,
        public readonly ?int $maxAge,
        public readonly ?string $codeChallenge,
        public readonly ?string $codeChallengeMethod,
    ) {
    }

    /**
     * Whether the request is an OpenID Connect request (has the openid scope).
     */
    public function isOpenId(): bool
    {
        return in_array('openid', $this->scopes, true);
    }

    /**
     * Whether a prompt value was sent.
     */
    public function prompts(string $value): bool
    {
        return in_array($value, $this->prompt, true);
    }

    /**
     * The query parameters handed to league/oauth2-server.
     *
     * @return array<string, string>
     */
    public function toLeagueQuery(): array
    {
        return array_filter([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(' ', $this->scopes),
            'state' => $this->state,
            'code_challenge' => $this->codeChallenge,
            'code_challenge_method' => $this->codeChallengeMethod,
        ], fn (?string $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['clientId'],
            (string) $data['clientName'],
            (string) $data['redirectUri'],
            array_values(array_map('strval', (array) $data['scopes'])),
            isset($data['state']) ? (string) $data['state'] : null,
            isset($data['nonce']) ? (string) $data['nonce'] : null,
            array_values(array_map('strval', (array) $data['prompt'])),
            isset($data['maxAge']) ? (int) $data['maxAge'] : null,
            isset($data['codeChallenge']) ? (string) $data['codeChallenge'] : null,
            isset($data['codeChallengeMethod']) ? (string) $data['codeChallengeMethod'] : null,
        );
    }
}
