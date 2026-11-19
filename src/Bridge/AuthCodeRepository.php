<?php

namespace Mpge\OpenIdProvider\Bridge;

use Laravel\Passport\Bridge\AuthCodeRepository as PassportAuthCodeRepository;
use Mpge\OpenIdProvider\Tokens\IssuanceContext;

/**
 * Passport's authorization code repository, aware of codes the token
 * endpoint has already claimed.
 *
 * The token endpoint marks a code as used with one atomic UPDATE before
 * league/oauth2-server sees it, so two concurrent requests can never both
 * redeem it. This repository then reports the claimed code as still valid
 * for the rest of that one request.
 *
 * @internal
 */
class AuthCodeRepository extends PassportAuthCodeRepository
{
    public function isAuthCodeRevoked(string $codeId): bool
    {
        $context = app(IssuanceContext::class);

        if ($context->claimedAuthCodeId !== null && hash_equals($context->claimedAuthCodeId, $codeId)) {
            return false;
        }

        return parent::isAuthCodeRevoked($codeId);
    }
}
