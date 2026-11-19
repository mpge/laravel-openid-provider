<?php

namespace Mpge\OpenIdProvider\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;
use Mpge\OpenIdProvider\Events\TokenReuseDetected;
use Mpge\OpenIdProvider\Tests\Concerns\InteractsWithOidc;
use Mpge\OpenIdProvider\Tests\TestCase;

class TokenReuseTest extends TestCase
{
    use InteractsWithOidc;

    public function test_reusing_an_authorization_code_fails_and_revokes_every_token_issued_from_it(): void
    {
        Event::fake([TokenReuseDetected::class]);
        $user = $this->createUser();
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($user);

        $code = $this->obtainCode($client);
        $tokens = $this->exchangeCode($client, $code)->assertOk()->json();

        // The access token works before the replay.
        $this->withToken($tokens['access_token'])->get('/oauth/userinfo')->assertOk();

        $this->exchangeCode($client, $code)
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_grant']);

        $this->withToken($tokens['access_token'])->get('/oauth/userinfo')
            ->assertStatus(401)
            ->assertJson(['error' => 'invalid_token']);

        $this->refresh($client, $tokens['refresh_token'])
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_grant']);

        $this->assertSame(0, Passport::token()->newQuery()->where('revoked', false)->count());
        Event::assertDispatched(TokenReuseDetected::class, fn (TokenReuseDetected $event): bool => $event->type === TokenReuseDetected::AUTHORIZATION_CODE);
    }

    public function test_refresh_tokens_rotate_on_every_use(): void
    {
        $user = $this->createUser();
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($user, time() - 10);

        $first = $this->exchangeCode($client, $this->obtainCode($client))->json();
        $second = $this->refresh($client, $first['refresh_token'])->assertOk()->json();

        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->assertNotSame($first['access_token'], $second['access_token']);

        // The ID token from a refresh keeps the original auth_time and has no nonce.
        $original = $this->parseJwt($first['id_token'])->claims();
        $refreshed = $this->parseJwt($second['id_token'])->claims();
        $this->assertSame($original->get('auth_time'), $refreshed->get('auth_time'));
        $this->assertSame($original->get('sub'), $refreshed->get('sub'));
        $this->assertFalse($refreshed->has('nonce'));

        // The old access token was revoked by the rotation.
        $this->withToken($first['access_token'])->get('/oauth/userinfo')->assertStatus(401);
        $this->withToken($second['access_token'])->get('/oauth/userinfo')->assertOk();
    }

    public function test_presenting_a_used_refresh_token_revokes_the_whole_chain(): void
    {
        Event::fake([TokenReuseDetected::class]);
        $user = $this->createUser();
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($user);

        $first = $this->exchangeCode($client, $this->obtainCode($client))->json();
        $second = $this->refresh($client, $first['refresh_token'])->assertOk()->json();
        $third = $this->refresh($client, $second['refresh_token'])->assertOk()->json();

        // An attacker replays the first refresh token.
        $this->refresh($client, $first['refresh_token'])
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_grant']);

        // Every descendant is now dead.
        $this->refresh($client, $third['refresh_token'])->assertStatus(400);
        $this->withToken($third['access_token'])->get('/oauth/userinfo')->assertStatus(401);

        Event::assertDispatched(TokenReuseDetected::class, fn (TokenReuseDetected $event): bool => $event->type === TokenReuseDetected::REFRESH_TOKEN);
    }

    public function test_refresh_cannot_escalate_scope(): void
    {
        $user = $this->createUser();
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($user);

        $tokens = $this->exchangeCode($client, $this->obtainCode($client, ['scope' => 'openid email']))->json();

        $this->refresh($client, $tokens['refresh_token'], 'openid email profile')
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_scope']);
    }

    public function test_refresh_can_narrow_scope(): void
    {
        $user = $this->createUser();
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($user);

        $tokens = $this->exchangeCode($client, $this->obtainCode($client, ['scope' => 'openid email profile']))->json();
        $narrowed = $this->refresh($client, $tokens['refresh_token'], 'openid email')->assertOk()->json();

        $claims = $this->withToken($narrowed['access_token'])->get('/oauth/userinfo')->assertOk()->json();
        $this->assertArrayHasKey('email', $claims);
        $this->assertArrayNotHasKey('name', $claims);
    }

    public function test_another_client_cannot_use_a_refresh_token(): void
    {
        $user = $this->createUser();
        $client = $this->createConfidentialClient();
        $other = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($user);

        $tokens = $this->exchangeCode($client, $this->obtainCode($client))->json();

        $this->refresh($other, $tokens['refresh_token'])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);

        // The legitimate client's token was not burned by the failed attempt.
        $this->refresh($client, $tokens['refresh_token'])->assertOk();
    }
}
