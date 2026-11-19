<?php

namespace Mpge\OpenIdProvider\Tests\Feature;

use Mpge\OpenIdProvider\Tests\Concerns\InteractsWithOidc;
use Mpge\OpenIdProvider\Tests\TestCase;

class PkceTest extends TestCase
{
    use InteractsWithOidc;

    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    private function challenge(string $verifier = self::VERIFIER): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public function test_public_client_without_pkce_is_refused(): void
    {
        $this->signIn($this->createUser());
        $client = $this->createPublicClient();

        $query = $this->redirectQuery($this->authorize($this->authorizationParams($client)));

        $this->assertSame('invalid_request', $query['error']);
        $this->assertStringContainsString('PKCE', $query['error_description']);
    }

    public function test_plain_pkce_is_refused(): void
    {
        $this->signIn($this->createUser());
        $client = $this->createPublicClient();

        foreach ([['code_challenge_method' => 'plain'], []] as $method) {
            $query = $this->redirectQuery($this->authorize($this->authorizationParams($client, ['code_challenge' => self::VERIFIER] + $method)));

            $this->assertSame('invalid_request', $query['error']);
            $this->assertStringContainsString('S256', $query['error_description']);
        }
    }

    public function test_missing_code_verifier_is_refused(): void
    {
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($this->createUser());

        $code = $this->obtainCode($client, ['code_challenge' => $this->challenge(), 'code_challenge_method' => 'S256']);

        $this->exchangeCode($client, $code)->assertStatus(400)->assertJson(['error' => 'invalid_request']);
    }

    public function test_wrong_code_verifier_is_refused(): void
    {
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($this->createUser());

        $code = $this->obtainCode($client, ['code_challenge' => $this->challenge(), 'code_challenge_method' => 'S256']);

        $this->exchangeCode($client, $code, ['code_verifier' => str_repeat('x', 43)])
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_grant']);
    }

    public function test_code_verifier_without_a_challenge_is_refused(): void
    {
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($this->createUser());

        $code = $this->obtainCode($client);

        $this->exchangeCode($client, $code, ['code_verifier' => self::VERIFIER])
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_request']);
    }

    public function test_confidential_client_with_correct_verifier_succeeds(): void
    {
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($this->createUser());

        $code = $this->obtainCode($client, ['code_challenge' => $this->challenge(), 'code_challenge_method' => 'S256']);

        $this->exchangeCode($client, $code, ['code_verifier' => self::VERIFIER])->assertOk();
    }

    public function test_pkce_can_be_required_for_confidential_clients(): void
    {
        config(['openid-provider.pkce.required_for_confidential_clients' => true]);
        $this->signIn($this->createUser());
        $client = $this->createConfidentialClient();

        $query = $this->redirectQuery($this->authorize($this->authorizationParams($client)));

        $this->assertSame('invalid_request', $query['error']);
    }
}
