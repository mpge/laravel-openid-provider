<?php

namespace Mpge\OpenIdProvider\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolves the value of one claim for one user.
 *
 * Reference an implementation by class name in the `scopes` map of
 * config/openid-provider.php. Instances are resolved from the container, so
 * constructor dependencies are injected.
 */
interface ClaimResolver
{
    /**
     * Return the claim value, or null to leave the claim out.
     *
     * @param  string  $claim  The claim being resolved, e.g. "email_verified".
     */
    public function resolve(Authenticatable $user, string $claim): mixed;
}
