<?php

namespace Mpge\OpenIdProvider\Claims\Resolvers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Mpge\OpenIdProvider\Contracts\ClaimResolver;

/**
 * Resolves `email_verified` as a boolean.
 *
 * Uses Laravel's MustVerifyEmail contract when the model implements it,
 * otherwise checks whether an `email_verified_at` attribute is set.
 */
class EmailVerified implements ClaimResolver
{
    public function resolve(Authenticatable $user, string $claim): bool
    {
        if ($user instanceof MustVerifyEmail) {
            return $user->hasVerifiedEmail();
        }

        return data_get($user, 'email_verified_at') !== null;
    }
}
