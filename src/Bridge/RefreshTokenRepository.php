<?php

namespace Mpge\OpenIdProvider\Bridge;

use Laravel\Passport\Bridge\RefreshTokenRepository as PassportRefreshTokenRepository;
use Mpge\OpenIdProvider\Tokens\IssuanceContext;

/**
 * Passport's refresh token repository, aware of refresh tokens the token
 * endpoint has already claimed.
 *
 * See {@see AuthCodeRepository} for why the claim happens before
 * league/oauth2-server validates the token.
 *
 * @internal
 */
class RefreshTokenRepository extends PassportRefreshTokenRepository
{
    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $context = app(IssuanceContext::class);

        if ($context->claimedRefreshTokenId !== null && hash_equals($context->claimedRefreshTokenId, $tokenId)) {
            return false;
        }

        return parent::isRefreshTokenRevoked($tokenId);
    }
}
