<?php

namespace Mpge\OpenIdProvider\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Laravel\Passport\Passport;
use Mpge\OpenIdProvider\Grants\GrantRepository;
use Mpge\OpenIdProvider\Support\AccessTokenValidator;
use Mpge\OpenIdProvider\Support\ClientAuthenticator;
use Mpge\OpenIdProvider\Tokens\EncryptedPayloadReader;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The token revocation endpoint (RFC 7009).
 *
 * The client must authenticate (public clients send their client_id). The
 * response is always 200 with an empty body once the client is
 * authenticated, whether or not the token existed, was already revoked or
 * belonged to another client, so the endpoint reveals nothing about tokens.
 *
 * Revoking a refresh token revokes its whole grant (every access and
 * refresh token descended from the same authorization). Revoking an access
 * token revokes that access token only.
 */
class RevocationController
{
    public function __construct(
        private readonly ClientAuthenticator $clients,
        private readonly EncryptedPayloadReader $payloads,
        private readonly AccessTokenValidator $accessTokens,
        private readonly GrantRepository $grants,
    ) {
    }

    /**
     * POST /oauth/revoke
     */
    public function __invoke(ServerRequestInterface $request): Response|JsonResponse
    {
        if ($this->clients->hasConflictingCredentials($request)) {
            return $this->error('invalid_request', 'Use exactly one client authentication method.', 400);
        }

        $client = $this->clients->authenticate($request);

        if ($client === null) {
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

        $hint = is_string($body['token_type_hint'] ?? null) ? $body['token_type_hint'] : null;
        $clientId = $client->getIdentifier();

        if ($hint === 'access_token') {
            $this->revokeAccessToken($token, $clientId) || $this->revokeRefreshToken($token, $clientId);
        } else {
            $this->revokeRefreshToken($token, $clientId) || $this->revokeAccessToken($token, $clientId);
        }

        return new Response('', 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /**
     * Returns true when the value was recognised as a refresh token.
     */
    private function revokeRefreshToken(string $token, string $clientId): bool
    {
        $payload = $this->payloads->refreshToken($token);

        if ($payload === null) {
            return false;
        }

        if (hash_equals($payload['client_id'], $clientId)) {
            Passport::refreshToken()->newQuery()->whereKey($payload['refresh_token_id'])->update(['revoked' => true]);
            Passport::token()->newQuery()->whereKey($payload['access_token_id'])->update(['revoked' => true]);

            $grant = $this->grants->findByAccessToken($payload['access_token_id']);

            if ($grant !== null) {
                $this->grants->revoke($grant->id);
            }
        }

        return true;
    }

    /**
     * Returns true when the value was recognised as an access token.
     */
    private function revokeAccessToken(string $token, string $clientId): bool
    {
        $attributes = $this->accessTokens->validate($token);

        if ($attributes === null) {
            return false;
        }

        if (hash_equals($attributes['client_id'], $clientId)) {
            Passport::token()->newQuery()->whereKey($attributes['token_id'])->update(['revoked' => true]);
        }

        return true;
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
