<?php

namespace Mpge\OpenIdProvider\Tokens;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use Mpge\OpenIdProvider\Support\UserResolver;
use SensitiveParameter;

/**
 * league/oauth2-server bearer token response that adds an `id_token` when
 * the `openid` scope was granted to a user.
 *
 * ID tokens are issued for the authorization_code grant and, with the
 * original auth_time and no nonce, for the refresh_token grant (OpenID
 * Connect Core 12.2). client_credentials tokens have no user and never get
 * an ID token.
 */
class IdTokenResponse extends BearerTokenResponse
{
    /**
     * @return array<string, string>
     */
    protected function getExtraParams(#[SensitiveParameter] AccessTokenEntityInterface $accessToken): array
    {
        $scopes = array_map(
            fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(),
            $accessToken->getScopes(),
        );

        $userId = $accessToken->getUserIdentifier();

        if (! in_array('openid', $scopes, true) || $userId === null || $userId === '') {
            return [];
        }

        $context = app(IssuanceContext::class);

        if (! in_array($context->grantType, ['authorization_code', 'refresh_token'], true)) {
            return [];
        }

        $user = app(UserResolver::class)->find((string) $userId)
            ?? throw OAuthServerException::invalidGrant('The user for this grant no longer exists.');

        $grant = $context->grant;

        return [
            'id_token' => app(IdTokenIssuer::class)->issue(
                $user,
                $accessToken->getClient()->getIdentifier(),
                $scopes,
                $grant?->authTime,
                $context->grantType === 'authorization_code' ? $grant?->nonce : null,
            ),
        ];
    }
}
