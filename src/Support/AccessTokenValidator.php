<?php

namespace Mpge\OpenIdProvider\Support;

use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use SensitiveParameter;

/**
 * Validates a Passport access token with league/oauth2-server's resource
 * server (signature, expiry and revocation).
 *
 * @internal
 */
class AccessTokenValidator
{
    public function __construct(
        private readonly ResourceServer $server,
        private readonly ClientLookup $clients,
    ) {
    }

    /**
     * The token's attributes, or null when it is not valid. A token whose
     * client has been revoked is not valid.
     *
     * @return array{token_id: string, client_id: string, user_id: string|null, scopes: list<string>}|null
     */
    public function validate(#[SensitiveParameter] string $token): ?array
    {
        if ($token === '' || strlen($token) > 8192) {
            return null;
        }

        $request = (new Psr17Factory)
            ->createServerRequest('GET', 'http://localhost')
            ->withHeader('Authorization', 'Bearer '.$token);

        try {
            $validated = $this->server->validateAuthenticatedRequest($request);
        } catch (OAuthServerException) {
            return null;
        }

        $tokenId = $validated->getAttribute('oauth_access_token_id');
        $clientId = $validated->getAttribute('oauth_client_id');
        $userId = $validated->getAttribute('oauth_user_id');
        $scopes = $validated->getAttribute('oauth_scopes');

        if (! is_string($tokenId) || ! is_string($clientId) || $this->clients->active($clientId) === null) {
            return null;
        }

        return [
            'token_id' => $tokenId,
            'client_id' => $clientId,
            'user_id' => is_scalar($userId) && (string) $userId !== '' ? (string) $userId : null,
            'scopes' => is_array($scopes) ? array_values(array_map('strval', $scopes)) : [],
        ];
    }
}
