<?php

namespace Mpge\OpenIdProvider\Claims;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use Mpge\OpenIdProvider\Contracts\ClaimResolver;
use Mpge\OpenIdProvider\Contracts\OidcSubject;

/**
 * Turns a user and a set of granted scopes into OpenID Connect claims.
 *
 * Only claims belonging to granted scopes are released. `sub` is always
 * present. A resolver that throws makes the whole request fail, so a broken
 * resolver never silently releases partial or wrong data.
 */
class ClaimsResolver
{
    /**
     * Claims that are set by the protocol and can never be overridden by a
     * scope mapping.
     *
     * @var list<string>
     */
    public const PROTECTED_CLAIMS = [
        'iss', 'sub', 'aud', 'exp', 'iat', 'nbf', 'jti', 'auth_time', 'nonce', 'azp', 'at_hash', 'c_hash', 'acr', 'amr',
    ];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * The subject identifier for a user.
     */
    public function subject(Authenticatable $user): string
    {
        $subject = $user instanceof OidcSubject
            ? $user->getOidcSubject()
            : (string) $user->getAuthIdentifier();

        if ($subject === '') {
            throw new InvalidArgumentException('The OpenID subject for a user must not be empty.');
        }

        return $subject;
    }

    /**
     * Resolve the claims released by the given scopes, including `sub`.
     *
     * @param  array<int, string>  $scopes
     * @return array<string, mixed>
     */
    public function claimsFor(Authenticatable $user, array $scopes): array
    {
        return ['sub' => $this->subject($user)] + $this->scopeClaims($user, $scopes);
    }

    /**
     * Resolve only the scope-based claims (no `sub`).
     *
     * @param  array<int, string>  $scopes
     * @return array<string, mixed>
     */
    public function scopeClaims(Authenticatable $user, array $scopes): array
    {
        $claims = [];

        foreach ($this->claimMap($scopes) as $claim => $resolver) {
            $value = $this->resolveValue($user, $claim, $resolver);

            if ($value instanceof Arrayable) {
                $value = $value->toArray();
            }

            if ($value !== null) {
                $claims[$claim] = $value;
            }
        }

        return $claims;
    }

    /**
     * The claim names each configured scope can release, keyed by scope.
     *
     * @return array<string, list<string>>
     */
    public function claimNamesByScope(): array
    {
        $names = [];

        foreach ($this->configuredScopes() as $scope => $definition) {
            $names[$scope] = array_values(array_filter(
                array_map('strval', array_keys($definition['claims'] ?? [])),
                fn (string $claim): bool => ! in_array($claim, self::PROTECTED_CLAIMS, true),
            ));
        }

        return $names;
    }

    /**
     * The merged claim => resolver map for the given scopes.
     *
     * @param  array<int, string>  $scopes
     * @return array<string, mixed>
     */
    private function claimMap(array $scopes): array
    {
        $configured = $this->configuredScopes();
        $map = [];

        foreach ($scopes as $scope) {
            foreach ($configured[$scope]['claims'] ?? [] as $claim => $resolver) {
                if (! in_array($claim, self::PROTECTED_CLAIMS, true)) {
                    $map[(string) $claim] = $resolver;
                }
            }
        }

        return $map;
    }

    private function resolveValue(Authenticatable $user, string $claim, mixed $resolver): mixed
    {
        if (is_string($resolver) && class_exists($resolver)) {
            $instance = $this->container->make($resolver);

            if (! $instance instanceof ClaimResolver) {
                throw new InvalidArgumentException("Claim resolver [{$resolver}] must implement ".ClaimResolver::class.'.');
            }

            return $instance->resolve($user, $claim);
        }

        if (is_string($resolver)) {
            return data_get($user, $resolver);
        }

        if (is_array($resolver) && count($resolver) === 2 && is_string($resolver[0]) && is_string($resolver[1])) {
            return $this->container->make($resolver[0])->{$resolver[1]}($user, $claim);
        }

        if (is_callable($resolver)) {
            return $resolver($user, $claim);
        }

        throw new InvalidArgumentException("The resolver for claim [{$claim}] is not valid.");
    }

    /**
     * @return array<string, array{description?: string, claims?: array<string, mixed>}>
     */
    private function configuredScopes(): array
    {
        /** @var array<string, array{description?: string, claims?: array<string, mixed>}> $scopes */
        $scopes = (array) config('openid-provider.scopes', []);

        return $scopes;
    }
}
