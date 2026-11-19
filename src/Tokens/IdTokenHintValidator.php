<?php

namespace Mpge\OpenIdProvider\Tokens;

use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Mpge\OpenIdProvider\Keys\KeyStore;
use Mpge\OpenIdProvider\Support\Issuer;
use SensitiveParameter;
use Throwable;

/**
 * Verifies an id_token_hint sent to the logout endpoint.
 *
 * The signature is verified by lcobucci/jwt against the published keys
 * (current, next and previous) and the issuer must match. Expiry is not
 * checked: RP-Initiated Logout 1.0 section 2 asks providers to accept ID
 * tokens whose exp has passed, because clients usually log out long after
 * the ID token expired.
 */
class IdTokenHintValidator
{
    public function __construct(private readonly KeyStore $keys) {}

    /**
     * The subject and client of a valid hint, or null when it is not valid.
     *
     * @return array{sub: string, client_id: string}|null
     */
    public function validate(#[SensitiveParameter] string $hint): ?array
    {
        if ($hint === '' || strlen($hint) > 8192) {
            return null;
        }

        try {
            $token = (new Parser(new JoseEncoder))->parse($hint);
        } catch (Throwable) {
            return null;
        }

        if (! $token instanceof Plain || $token->headers()->get('alg') !== 'RS256' || ! $this->verified($token)) {
            return null;
        }

        $subject = $token->claims()->get('sub');
        $audience = $token->claims()->get('aud');
        $audience = is_array($audience) ? array_values($audience) : [];
        $authorizedParty = $token->claims()->get('azp');

        // The client is the single audience, or azp when there are several.
        $clientId = count($audience) === 1 ? $audience[0] : $authorizedParty;

        if (! is_string($subject) || $subject === '' || ! is_string($clientId) || $clientId === ''
            || ! in_array($clientId, $audience, true)) {
            return null;
        }

        return ['sub' => $subject, 'client_id' => $clientId];
    }

    private function verified(Plain $token): bool
    {
        $validator = new Validator;
        $issuer = new IssuedBy(Issuer::identifier());
        $kid = $token->headers()->get('kid');

        foreach ($this->keys->published() as $key) {
            if (is_string($kid) && $kid !== $key->kid) {
                continue;
            }

            $signature = new SignedWith(new Sha256, InMemory::plainText($key->publicKeyPem()));

            if ($validator->validate($token, $signature, $issuer)) {
                return true;
            }
        }

        return false;
    }
}
