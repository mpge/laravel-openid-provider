<?php

namespace Mpge\OpenIdProvider\Tests\Feature;

use Laravel\Passport\Client;
use Mpge\OpenIdProvider\Tests\Concerns\InteractsWithOidc;
use Mpge\OpenIdProvider\Tests\TestCase;

class DiscoveryAndRevocationTest extends TestCase
{
    use InteractsWithOidc;

    public function test_discovery_lists_only_supported_features(): void
    {
        $document = $this->get('/.well-known/openid-configuration')->assertOk()->json();

        $this->assertSame('https://op.test', $document['issuer']);
        $this->assertSame('https://op.test/oauth/authorize', $document['authorization_endpoint']);
        $this->assertSame('https://op.test/oauth/token', $document['token_endpoint']);
        $this->assertSame('https://op.test/oauth/userinfo', $document['userinfo_endpoint']);
        $this->assertSame('https://op.test/oauth/revoke', $document['revocation_endpoint']);
        $this->assertSame('https://op.test/.well-known/jwks.json', $document['jwks_uri']);
        $this->assertSame(['code'], $document['response_types_supported']);
        $this->assertSame(['S256'], $document['code_challenge_methods_supported']);
        $this->assertSame(['public'], $document['subject_types_supported']);
        $this->assertSame(['RS256'], $document['id_token_signing_alg_values_supported']);
        $this->assertSame(['authorization_code', 'refresh_token', 'client_credentials'], $document['grant_types_supported']);
        $this->assertContains('openid', $document['scopes_supported']);
        $this->assertContains('email', $document['claims_supported']);
        $this->assertFalse($document['request_parameter_supported']);
        $this->assertArrayNotHasKey('registration_endpoint', $document);
        $this->assertArrayNotHasKey('end_session_endpoint', $document);
        $this->assertArrayNotHasKey('introspection_endpoint', $document);
    }

    /**
     * @return array{0: Client, 1: array<string, mixed>}
     */
    private function issuedTokens(): array
    {
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($this->createUser());

        return [$client, $this->exchangeCode($client, $this->obtainCode($client))->json()];
    }

    public function test_revoking_an_access_token(): void
    {
        [$client, $tokens] = $this->issuedTokens();

        $this->post('/oauth/revoke', [
            'token' => $tokens['access_token'],
            'token_type_hint' => 'access_token',
            'client_id' => (string) $client->getKey(),
            'client_secret' => $client->plainSecret,
        ])->assertOk()->assertContent('');

        $this->withToken($tokens['access_token'])->get('/oauth/userinfo')->assertStatus(401);
    }

    public function test_revoking_a_refresh_token_revokes_its_grant(): void
    {
        [$client, $tokens] = $this->issuedTokens();

        $this->withHeader('Authorization', 'Basic '.base64_encode($client->getKey().':'.$client->plainSecret))
            ->post('/oauth/revoke', ['token' => $tokens['refresh_token']])
            ->assertOk();

        $this->refresh($client, $tokens['refresh_token'])->assertStatus(400);
        $this->withToken($tokens['access_token'])->get('/oauth/userinfo')->assertStatus(401);
    }

    public function test_revocation_never_reveals_whether_a_token_exists(): void
    {
        [$client, $tokens] = $this->issuedTokens();
        $other = $this->createConfidentialClient();

        foreach (['garbage', $tokens['access_token'], $tokens['refresh_token']] as $token) {
            $this->post('/oauth/revoke', [
                'token' => $token,
                'client_id' => (string) $other->getKey(),
                'client_secret' => $other->plainSecret,
            ])->assertOk()->assertContent('');
        }

        // Another client cannot revoke tokens it does not own.
        $this->withToken($tokens['access_token'])->get('/oauth/userinfo')->assertOk();
        $this->refresh($client, $tokens['refresh_token'])->assertOk();
    }

    public function test_revocation_requires_client_authentication(): void
    {
        [$client, $tokens] = $this->issuedTokens();

        $this->post('/oauth/revoke', [
            'token' => $tokens['access_token'],
            'client_id' => (string) $client->getKey(),
            'client_secret' => 'wrong',
        ])->assertStatus(401)->assertJson(['error' => 'invalid_client']);

        $this->post('/oauth/revoke', ['token' => $tokens['access_token']])->assertStatus(401);

        $this->withToken($tokens['access_token'])->get('/oauth/userinfo')->assertOk();
    }
}
