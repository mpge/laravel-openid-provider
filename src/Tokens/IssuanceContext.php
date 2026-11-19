<?php

namespace Mpge\OpenIdProvider\Tokens;

use Mpge\OpenIdProvider\Grants\Grant;

/**
 * Request-scoped state shared between the token endpoint, the repositories
 * and the ID token response while one token request is handled.
 *
 * @internal
 */
class IssuanceContext
{
    /**
     * The grant the tokens being issued belong to.
     */
    public ?Grant $grant = null;

    /**
     * The grant type of the request being handled.
     */
    public ?string $grantType = null;

    /**
     * The authorization code this request atomically claimed.
     */
    public ?string $claimedAuthCodeId = null;

    /**
     * The refresh token this request atomically claimed.
     */
    public ?string $claimedRefreshTokenId = null;

    /**
     * Reset the context for a new request.
     */
    public function reset(): void
    {
        $this->grant = null;
        $this->grantType = null;
        $this->claimedAuthCodeId = null;
        $this->claimedRefreshTokenId = null;
    }
}
