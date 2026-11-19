<?php

namespace Mpge\OpenIdProvider\Tests\Feature;

use Laravel\Passport\ClientRepository;
use Mpge\OpenIdProvider\Tests\Concerns\InteractsWithOidc;
use Mpge\OpenIdProvider\Tests\TestCase;

class TokenEndpointTest extends TestCase
{
    use InteractsWithOidc;

    public function test_expired_code_is_refused(): void
    {
        config(['openid-provider.authorization_code_ttl' => 1]);
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($this->createUser());

        $code = $this->obtainCode($client);
        sleep(2);

        $this->exchangeCode($client, $code)->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    }

    public function test_wrong_client_secret_is_refused(): void
    {
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($this->createUser());
        $code = $this->obtainCode($client);

        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $client->redirect_uris[0],
            'client_id' => (string) $client->getKey(),
            'client_secret' => 'wrong-secret',
        ])->assertStatus(401)->assertJson(['error' => 'invalid_client']);

        $this->withHeader('Authorization', 'Basic '.base64_encode($client->getKey().':wrong-secret'))
            ->post('/oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $client->redirect_uris[0],
            ])->assertStatus(401)->assertHeader('WWW-Authenticate');

        // A failed authentication does not burn the code.
        $this->flushHeaders();
        $this->exchangeCode($client, $code)->assertOk();
    }

    public function test_mixing_basic_and_post_client_authentication_is_refused(): void
    {
        $client = $this->createConfidentialClient();

        $this->withHeader('Authorization', 'Basic '.base64_encode($client->getKey().':'.$client->plainSecret))
            ->post('/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_secret' => $client->plainSecret,
            ])->assertStatus(400)->assertJson(['error' => 'invalid_request']);
    }

    public function test_revoked_client_is_refused_everywhere(): void
    {
        $user = $this->createUser();
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($user);

        $code = $this->obtainCode($client);
        $tokens = $this->exchangeCode($client, $this->obtainCode($client))->json();

        $client->forceFill(['revoked' => true])->save();
        \Illuminate\Support\Once::flush();

        $this->authorize($this->authorizationParams($client))->assertStatus(400)->assertSee('invalid_client');
        $this->exchangeCode($client, $code)->assertStatus(401)->assertJson(['error' => 'invalid_client']);
        $this->refresh($client, $tokens['refresh_token'])->assertStatus(401);
        $this->withToken($tokens['access_token'])->get('/oauth/userinfo')->assertStatus(401);
    }

    public function test_client_credentials_grant_issues_no_id_token(): void
    {
        $client = app(ClientRepository::class)->createClientCredentialsGrantClient('Machine');

        $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => (string) $client->getKey(),
            'client_secret' => $client->plainSecret,
        ])->assertOk()->assertJsonMissingPath('id_token')->assertJsonStructure(['access_token']);
    }

    public function test_disabled_and_unknown_grant_types_are_refused(): void
    {
        config(['openid-provider.grant_types' => ['authorization_code', 'refresh_token']]);
        $client = app(ClientRepository::class)->createClientCredentialsGrantClient('Machine');

        foreach (['client_credentials', 'password', 'implicit', 'urn:ietf:params:oauth:grant-type:device_code'] as $grantType) {
            $this->post('/oauth/token', [
                'grant_type' => $grantType,
                'client_id' => (string) $client->getKey(),
                'client_secret' => $client->plainSecret,
            ])->assertStatus(400)->assertJson(['error' => 'unsupported_grant_type']);
        }
    }
}
