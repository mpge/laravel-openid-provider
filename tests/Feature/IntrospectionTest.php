<?php

namespace Mpge\OpenIdProvider\Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Mpge\OpenIdProvider\Tests\Concerns\InteractsWithOidc;
use Mpge\OpenIdProvider\Tests\TestCase;
use Workbench\App\Models\User;

class IntrospectionTest extends TestCase
{
    use InteractsWithOidc;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('openid-provider.introspection.enabled', true);
    }

    /**
     * @return array{0: Client, 1: array<string, mixed>, 2: User}
     */
    private function issuedTokens(): array
    {
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $user = $this->createUser();
        $this->signIn($user);

        return [$client, $this->exchangeCode($client, $this->obtainCode($client))->json(), $user];
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function introspect(Client $caller, string $token, array $extra = []): TestResponse
    {
        return $this->post('/oauth/introspect', array_merge([
            'token' => $token,
            'client_id' => (string) $caller->getKey(),
            'client_secret' => (string) $caller->plainSecret,
        ], $extra));
    }

    public function test_discovery_lists_the_introspection_endpoint(): void
    {
        $document = $this->get('/.well-known/openid-configuration')->assertOk()->json();

        $this->assertSame('https://op.test/oauth/introspect', $document['introspection_endpoint']);
        $this->assertSame(['client_secret_basic', 'client_secret_post'], $document['introspection_endpoint_auth_methods_supported']);
        $this->assertArrayNotHasKey('end_session_endpoint', $document);
    }

    public function test_an_active_access_token_is_described(): void
    {
        [$client, $tokens, $user] = $this->issuedTokens();

        $response = $this->introspect($client, $tokens['access_token'])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $body = $response->json();
        $this->assertTrue($body['active']);
        $this->assertSame('openid profile email', $body['scope']);
        $this->assertSame((string) $client->getKey(), $body['client_id']);
        $this->assertSame((string) $client->getKey(), $body['aud']);
        $this->assertSame((string) $user->getKey(), $body['sub']);
        $this->assertSame('Bearer', $body['token_type']);
        $this->assertSame('https://op.test', $body['iss']);
        $this->assertGreaterThan(time(), $body['exp']);
        $this->assertLessThanOrEqual(time(), $body['iat']);
    }

    public function test_an_active_refresh_token_is_described(): void
    {
        [$client, $tokens, $user] = $this->issuedTokens();

        $body = $this->introspect($client, $tokens['refresh_token'], ['token_type_hint' => 'refresh_token'])
            ->assertOk()
            ->json();

        $this->assertTrue($body['active']);
        $this->assertSame('refresh_token', $body['token_type']);
        $this->assertSame((string) $client->getKey(), $body['client_id']);
        $this->assertSame((string) $user->getKey(), $body['sub']);
        $this->assertSame('openid profile email', $body['scope']);
        $this->assertGreaterThan(time(), $body['exp']);
    }

    public function test_a_wrong_hint_does_not_stop_the_lookup(): void
    {
        [$client, $tokens] = $this->issuedTokens();

        $this->assertTrue($this->introspect($client, $tokens['access_token'], ['token_type_hint' => 'refresh_token'])->json('active'));
        $this->assertTrue($this->introspect($client, $tokens['refresh_token'], ['token_type_hint' => 'access_token'])->json('active'));
    }

    public function test_unknown_and_garbage_tokens_are_inactive(): void
    {
        $client = $this->createConfidentialClient();

        foreach (['garbage', str_repeat('a', 9000), 'eyJhbGciOiJSUzI1NiJ9.e30.c2ln'] as $token) {
            $this->introspect($client, $token)->assertOk()->assertExactJson(['active' => false]);
        }
    }

    public function test_another_clients_tokens_are_inactive_unless_allowed(): void
    {
        [, $tokens] = $this->issuedTokens();
        $resourceServer = $this->createConfidentialClient(['https://rs.test/callback']);

        $this->introspect($resourceServer, $tokens['access_token'])->assertExactJson(['active' => false]);
        $this->introspect($resourceServer, $tokens['refresh_token'])->assertExactJson(['active' => false]);

        $owner = $this->parseJwt($tokens['access_token'])->claims()->get('aud')[0];
        config(['openid-provider.introspection.allowed_clients' => [(string) $resourceServer->getKey() => [$owner]]]);

        $this->assertTrue($this->introspect($resourceServer, $tokens['access_token'])->json('active'));
        $this->assertTrue($this->introspect($resourceServer, $tokens['refresh_token'])->json('active'));
    }

    public function test_revoked_tokens_are_inactive(): void
    {
        [$client, $tokens] = $this->issuedTokens();

        $this->post('/oauth/revoke', [
            'token' => $tokens['refresh_token'],
            'client_id' => (string) $client->getKey(),
            'client_secret' => $client->plainSecret,
        ])->assertOk();

        $this->introspect($client, $tokens['access_token'])->assertExactJson(['active' => false]);
        $this->introspect($client, $tokens['refresh_token'])->assertExactJson(['active' => false]);
    }

    public function test_a_used_refresh_token_is_inactive(): void
    {
        [$client, $tokens] = $this->issuedTokens();

        $this->refresh($client, $tokens['refresh_token'])->assertOk();

        $this->introspect($client, $tokens['refresh_token'])->assertExactJson(['active' => false]);
    }

    public function test_tokens_of_a_revoked_client_are_inactive(): void
    {
        [$client, $tokens] = $this->issuedTokens();
        $resourceServer = $this->createConfidentialClient(['https://rs.test/callback']);
        config(['openid-provider.introspection.allowed_clients' => [(string) $resourceServer->getKey() => [(string) $client->getKey()]]]);

        $client->forceFill(['revoked' => true])->save();

        $this->introspect($resourceServer, $tokens['access_token'])->assertExactJson(['active' => false]);
        $this->introspect($resourceServer, $tokens['refresh_token'])->assertExactJson(['active' => false]);
    }

    public function test_tokens_of_a_deleted_user_are_inactive(): void
    {
        [$client, $tokens, $user] = $this->issuedTokens();

        $user->delete();

        $this->introspect($client, $tokens['access_token'])->assertExactJson(['active' => false]);
        $this->introspect($client, $tokens['refresh_token'])->assertExactJson(['active' => false]);
    }

    public function test_client_authentication_is_required(): void
    {
        [$client, $tokens] = $this->issuedTokens();

        $this->post('/oauth/introspect', ['token' => $tokens['access_token']])
            ->assertStatus(401)
            ->assertJson(['error' => 'invalid_client']);

        $this->post('/oauth/introspect', [
            'token' => $tokens['access_token'],
            'client_id' => (string) $client->getKey(),
            'client_secret' => 'wrong',
        ])->assertStatus(401)->assertJsonMissing(['active' => true]);

        $this->withHeader('Authorization', 'Basic '.base64_encode($client->getKey().':wrong'))
            ->post('/oauth/introspect', ['token' => $tokens['access_token']])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Basic realm="OAuth"');
    }

    public function test_public_clients_cannot_introspect(): void
    {
        $public = $this->createPublicClient();

        $this->post('/oauth/introspect', ['token' => 'anything', 'client_id' => (string) $public->getKey()])
            ->assertStatus(401)
            ->assertJson(['error' => 'invalid_client']);
    }

    public function test_the_token_parameter_is_required(): void
    {
        $client = $this->createConfidentialClient();

        $this->post('/oauth/introspect', [
            'client_id' => (string) $client->getKey(),
            'client_secret' => $client->plainSecret,
        ])->assertStatus(400)->assertJson(['error' => 'invalid_request']);
    }

    public function test_basic_authentication_works(): void
    {
        [$client, $tokens] = $this->issuedTokens();

        $this->withHeader('Authorization', 'Basic '.base64_encode($client->getKey().':'.$client->plainSecret))
            ->post('/oauth/introspect', ['token' => $tokens['access_token']])
            ->assertOk()
            ->assertJson(['active' => true]);
    }
}
