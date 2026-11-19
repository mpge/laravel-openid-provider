<?php

namespace Mpge\OpenIdProvider\Support;

use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

/**
 * Finds Passport clients without caching, so a revoked client is refused
 * immediately.
 *
 * @internal
 */
class ClientLookup
{
    /**
     * Find a client that exists and is not revoked.
     */
    public function active(?string $clientId): ?Client
    {
        if ($clientId === null || $clientId === '' || strlen($clientId) > 255) {
            return null;
        }

        $model = Passport::client();

        if (in_array($model->getKeyName(), $model->uniqueIds(), true) && ! Str::isUuid($clientId) && ! Str::isUlid($clientId)) {
            return null;
        }

        $client = $model->newQuery()->find($clientId);

        return $client instanceof Client && ! $client->getAttribute('revoked') ? $client : null;
    }
}
