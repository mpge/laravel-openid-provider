<?php

namespace Mpge\OpenIdProvider\Keys;

use OpenSSLAsymmetricKey;
use RuntimeException;
use SensitiveParameter;

/**
 * An RSA key pair used to sign ID tokens, with its key ID.
 *
 * The private key is only handed to the JWT signer. Debug output never
 * includes it.
 */
final class SigningKey
{
    private readonly string $publicKeyPem;

    /** @var array{n: string, e: string} */
    private readonly array $components;

    /**
     * The key ID published in the JWKS and in the ID token header.
     */
    public readonly string $kid;

    public function __construct(
        #[SensitiveParameter] private readonly string $privateKeyPem,
        ?string $kid = null,
    ) {
        $key = openssl_pkey_get_private($privateKeyPem);

        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('The OpenID signing key could not be read as a private key.');
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ! isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new RuntimeException('The OpenID signing key must be an RSA key.');
        }

        if (($details['bits'] ?? 0) < 2048) {
            throw new RuntimeException('The OpenID signing key must be at least 2048 bits.');
        }

        $this->publicKeyPem = (string) $details['key'];
        $this->components = [
            'n' => self::base64Url((string) $details['rsa']['n']),
            'e' => self::base64Url((string) $details['rsa']['e']),
        ];
        $this->kid = $kid !== null && $kid !== '' ? $kid : $this->thumbprint();
    }

    /**
     * The PEM encoded private key, for the JWT signer only.
     *
     * @return non-empty-string
     */
    public function privateKeyPem(): string
    {
        return $this->privateKeyPem !== '' ? $this->privateKeyPem : throw new RuntimeException('Empty signing key.');
    }

    /**
     * The PEM encoded public key.
     *
     * @return non-empty-string
     */
    public function publicKeyPem(): string
    {
        return $this->publicKeyPem !== '' ? $this->publicKeyPem : throw new RuntimeException('Empty public key.');
    }

    /**
     * The public key as a JSON Web Key (RFC 7517).
     *
     * @return array{kty: string, use: string, alg: string, kid: string, n: string, e: string}
     */
    public function toJwk(): array
    {
        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $this->kid,
            'n' => $this->components['n'],
            'e' => $this->components['e'],
        ];
    }

    /**
     * The RFC 7638 JWK thumbprint, used as the default key ID.
     */
    public function thumbprint(): string
    {
        $canonical = json_encode([
            'e' => $this->components['e'],
            'kty' => 'RSA',
            'n' => $this->components['n'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return self::base64Url(hash('sha256', $canonical, true));
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['kid' => $this->kid];
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
