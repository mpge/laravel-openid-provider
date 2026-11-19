<?php

namespace Mpge\OpenIdProvider\Tokens;

use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Mpge\OpenIdProvider\Claims\ClaimsResolver;
use Mpge\OpenIdProvider\Keys\KeyStore;
use Mpge\OpenIdProvider\Support\Issuer;

/**
 * Builds and signs ID tokens (RS256) with lcobucci/jwt.
 */
class IdTokenIssuer
{
    public function __construct(
        private readonly KeyStore $keys,
        private readonly ClaimsResolver $claims,
    ) {
    }

    /**
     * Issue a signed ID token.
     *
     * @param  array<int, string>  $scopes  The scopes granted to the client.
     * @param  int|null  $authTime  When the user last authenticated, if known.
     * @param  string|null  $nonce  The nonce from the authorization request.
     */
    public function issue(Authenticatable $user, string $clientId, array $scopes, ?int $authTime, ?string $nonce): string
    {
        $key = $this->keys->current();

        $configuration = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($key->privateKeyPem()),
            InMemory::plainText($key->publicKeyPem()),
        );

        // Whole seconds, so iat and exp are encoded as integers.
        $now = new DateTimeImmutable('@'.time());
        $ttl = max(1, (int) config('openid-provider.id_token_ttl', 3600));
        $audience = [$clientId];

        $builder = $configuration->builder()
            ->withHeader('kid', $key->kid)
            ->issuedBy(Issuer::identifier())
            ->relatedTo($this->claims->subject($user))
            ->permittedFor(...$audience)
            ->issuedAt($now)
            ->expiresAt($now->modify("+{$ttl} seconds"));

        if ($authTime !== null) {
            $builder = $builder->withClaim('auth_time', $authTime);
        }

        if ($nonce !== null && $nonce !== '') {
            $builder = $builder->withClaim('nonce', $nonce);
        }

        // azp is only needed when the ID token has more than one audience,
        // or one audience different from the authorized party. The audience
        // is always exactly the requesting client, so this never applies
        // today, but the rule is kept explicit.
        if ($audience !== [$clientId]) {
            $builder = $builder->withClaim('azp', $clientId);
        }

        if (config('openid-provider.id_token.include_scope_claims', false) === true) {
            foreach ($this->claims->scopeClaims($user, $scopes) as $name => $value) {
                $builder = $builder->withClaim($name, $value);
            }
        }

        return $builder->getToken($configuration->signer(), $configuration->signingKey())->toString();
    }
}
