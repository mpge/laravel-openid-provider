<?php

namespace Mpge\OpenIdProvider\Claims\Resolvers;

use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Mpge\OpenIdProvider\Contracts\ClaimResolver;

/**
 * Resolves `updated_at` as a Unix timestamp, as OpenID Connect requires.
 */
class UpdatedAt implements ClaimResolver
{
    public function resolve(Authenticatable $user, string $claim): ?int
    {
        $value = data_get($user, 'updated_at');

        return $value instanceof DateTimeInterface ? $value->getTimestamp() : null;
    }
}
