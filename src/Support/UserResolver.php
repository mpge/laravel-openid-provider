<?php

namespace Mpge\OpenIdProvider\Support;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use RuntimeException;

/**
 * Loads users through the user provider of the configured guard.
 *
 * @internal
 */
class UserResolver
{
    private ?UserProvider $provider = null;

    public function __construct(private readonly AuthManager $auth) {}

    /**
     * Find a user by the identifier stored in a token.
     */
    public function find(?string $identifier): ?Authenticatable
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        return $this->provider()->retrieveById($identifier);
    }

    private function provider(): UserProvider
    {
        if ($this->provider === null) {
            $guard = (string) config('openid-provider.guard', 'web');
            $providerName = config("auth.guards.{$guard}.provider");

            $this->provider = $this->auth->createUserProvider(is_string($providerName) ? $providerName : null)
                ?? throw new RuntimeException("No user provider is configured for guard [{$guard}].");
        }

        return $this->provider;
    }
}
