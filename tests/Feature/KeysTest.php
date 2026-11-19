<?php

namespace Mpge\OpenIdProvider\Tests\Feature;

use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Mpge\OpenIdProvider\Keys\KeyStore;
use Mpge\OpenIdProvider\Tests\Concerns\InteractsWithOidc;
use Mpge\OpenIdProvider\Tests\TestCase;

class KeysTest extends TestCase
{
    use InteractsWithOidc;

    private function idToken(): string
    {
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($this->createUser());

        return $this->exchangeCode($client, $this->obtainCode($client))->assertOk()->json('id_token');
    }

    /**
     * Verify a JWT against the JWKS the provider publishes, the way a client would.
     */
    private function verifyWithJwks(string $jwt): bool
    {
        $token = $this->parseJwt($jwt);
        $jwks = $this->get('/.well-known/jwks.json')->assertOk()->json('keys');

        foreach ($jwks as $jwk) {
            if ($jwk['kid'] === $token->headers()->get('kid')) {
                $pem = $this->pemFromJwk($jwk);

                return (new Validator)->validate($token, new SignedWith(new Sha256, InMemory::plainText($pem)));
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $jwk
     */
    private function pemFromJwk(array $jwk): string
    {
        $key = \phpseclib4\Crypt\PublicKeyLoader::load([
            'n' => new \phpseclib4\Math\BigInteger(base64_decode(strtr($jwk['n'], '-_', '+/')), 256),
            'e' => new \phpseclib4\Math\BigInteger(base64_decode(strtr($jwk['e'], '-_', '+/')), 256),
        ]);

        return (string) $key;
    }

    public function test_jwks_publishes_the_current_key_with_a_kid(): void
    {
        $keys = $this->get('/.well-known/jwks.json')->assertOk()->json('keys');

        $this->assertCount(1, $keys);
        $this->assertSame(['kty', 'use', 'alg', 'kid', 'n', 'e'], array_keys($keys[0]));
        $this->assertSame('RS256', $keys[0]['alg']);
        $this->assertNotEmpty($keys[0]['kid']);
    }

    public function test_rotation_publishes_two_keys_and_tokens_signed_by_each_verify(): void
    {
        $tokenFromOldKey = $this->idToken();
        $oldKid = $this->parseJwt($tokenFromOldKey)->headers()->get('kid');

        $this->artisan('openid-provider:rotate-keys', ['--prepare' => true])->assertSuccessful();

        $keys = $this->get('/.well-known/jwks.json')->json('keys');
        $this->assertCount(2, $keys);
        $this->assertSame($oldKid, $keys[0]['kid'], 'The current key still signs until promotion.');
        $this->assertNotSame($keys[0]['kid'], $keys[1]['kid']);

        $this->artisan('openid-provider:rotate-keys', ['--promote' => true])->assertSuccessful();
        app(KeyStore::class)->forgetLoaded();

        $tokenFromNewKey = $this->idToken();
        $newKid = $this->parseJwt($tokenFromNewKey)->headers()->get('kid');
        $this->assertNotSame($oldKid, $newKid);

        $keys = $this->get('/.well-known/jwks.json')->json('keys');
        $this->assertCount(2, $keys);
        $this->assertSame([$newKid, $oldKid], array_column($keys, 'kid'));

        $this->assertTrue($this->verifyWithJwks($tokenFromOldKey), 'Token from the previous key must verify.');
        $this->assertTrue($this->verifyWithJwks($tokenFromNewKey), 'Token from the current key must verify.');

        $this->artisan('openid-provider:rotate-keys', ['--retire' => true])->assertSuccessful();
        $this->assertSame([$newKid], array_column($this->get('/.well-known/jwks.json')->json('keys'), 'kid'));
        $this->assertFalse($this->verifyWithJwks($tokenFromOldKey));
    }

    public function test_configured_kid_survives_promotion_as_the_previous_key(): void
    {
        config(['openid-provider.keys.current_kid' => 'legacy-kid']);
        $this->assertSame('legacy-kid', $this->get('/.well-known/jwks.json')->json('keys.0.kid'));

        $this->artisan('openid-provider:rotate-keys', ['--prepare' => true])->assertSuccessful();
        $this->artisan('openid-provider:rotate-keys', ['--promote' => true])->assertSuccessful();
        config(['openid-provider.keys.current_kid' => null]);
        app(KeyStore::class)->forgetLoaded();

        $kids = array_column($this->get('/.well-known/jwks.json')->json('keys'), 'kid');
        $this->assertSame('legacy-kid', $kids[1]);
    }

    public function test_promote_without_a_prepared_key_fails(): void
    {
        $this->artisan('openid-provider:rotate-keys', ['--promote' => true])->assertFailed();
    }

    public function test_status_lists_the_keys(): void
    {
        $this->artisan('openid-provider:rotate-keys')->expectsOutputToContain('Passport private key')->assertSuccessful();
    }
}
