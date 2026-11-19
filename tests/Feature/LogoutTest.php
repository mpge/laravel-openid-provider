<?php

namespace Mpge\OpenIdProvider\Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Mpge\OpenIdProvider\Http\Controllers\LogoutController;
use Mpge\OpenIdProvider\Tests\Concerns\InteractsWithOidc;
use Mpge\OpenIdProvider\Tests\TestCase;
use Workbench\App\Models\User;

class LogoutTest extends TestCase
{
    use InteractsWithOidc;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('openid-provider.logout.enabled', true);
    }

    /**
     * Sign in, run the code flow and return the client, user and ID token.
     *
     * @return array{0: Client, 1: User, 2: string}
     */
    private function signedInWithIdToken(): array
    {
        $client = $this->createConfidentialClient(['https://rp.test/callback', 'https://rp.test/logged-out']);
        $this->skipConsentFor($client);
        $user = $this->createUser();
        $this->signIn($user);

        $idToken = $this->exchangeCode($client, $this->obtainCode($client))->json('id_token');
        $this->assertIsString($idToken);

        return [$client, $user, $idToken];
    }

    /**
     * @param  array<string, string>  $params
     */
    private function logout(array $params): TestResponse
    {
        return $this->get('/oauth/logout?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }

    private function challengeFrom(TestResponse $response): string
    {
        preg_match('/name="logout_request" value="([^"]+)"/', (string) $response->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null, 'Confirmation page has no logout_request.');

        return $matches[1];
    }

    public function test_discovery_lists_the_end_session_endpoint(): void
    {
        $document = $this->get('/.well-known/openid-configuration')->assertOk()->json();

        $this->assertSame('https://op.test/oauth/logout', $document['end_session_endpoint']);
        $this->assertArrayNotHasKey('introspection_endpoint', $document);
    }

    public function test_a_verified_hint_signs_out_and_redirects_with_state(): void
    {
        [, , $idToken] = $this->signedInWithIdToken();

        $this->logout([
            'id_token_hint' => $idToken,
            'post_logout_redirect_uri' => 'https://rp.test/logged-out',
            'state' => 'a b&c',
        ])->assertRedirect('https://rp.test/logged-out?state=a%20b%26c');

        $this->assertGuest();
    }

    public function test_logout_also_accepts_post(): void
    {
        [, , $idToken] = $this->signedInWithIdToken();

        $this->post('/oauth/logout', [
            'id_token_hint' => $idToken,
            'post_logout_redirect_uri' => 'https://rp.test/logged-out',
        ])->assertRedirect('https://rp.test/logged-out');

        $this->assertGuest();
    }

    public function test_an_expired_hint_is_still_accepted(): void
    {
        [, , $idToken] = $this->signedInWithIdToken();

        $this->travel(2)->hours();

        $response = $this->logout(['id_token_hint' => $idToken])->assertRedirect();
        $this->assertSame('', trim((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH), '/'));
        $this->assertGuest();
    }

    public function test_without_a_hint_the_user_must_confirm(): void
    {
        [$client] = $this->signedInWithIdToken();

        $page = $this->logout(['client_id' => (string) $client->getKey(), 'post_logout_redirect_uri' => 'https://rp.test/logged-out'])
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertSee('Demo RP');
        $this->assertAuthenticated();

        $this->post('/oauth/logout/confirm', ['logout_request' => $this->challengeFrom($page), '_token' => session()->token()])
            ->assertRedirect('https://rp.test/logged-out');
        $this->assertGuest();
    }

    public function test_a_hint_for_another_user_needs_confirmation(): void
    {
        [, , $idToken] = $this->signedInWithIdToken();
        $this->signIn($this->createUser());

        $this->logout(['id_token_hint' => $idToken])->assertOk()->assertSee('logout_request', false);
        $this->assertAuthenticated();
    }

    public function test_a_tampered_hint_needs_confirmation(): void
    {
        [, , $idToken] = $this->signedInWithIdToken();
        [$header, $payload] = explode('.', $idToken);

        $this->logout(['id_token_hint' => $header.'.'.$payload.'.'.rtrim(strtr(base64_encode('forged'), '+/', '-_'), '=')])
            ->assertOk()
            ->assertSee('logout_request', false);
        $this->assertAuthenticated();
    }

    public function test_an_access_token_is_not_accepted_as_a_hint(): void
    {
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($this->createUser());
        $accessToken = $this->exchangeCode($client, $this->obtainCode($client))->json('access_token');

        // Signed with the same key as ID tokens, but it has no iss claim.
        $this->logout(['id_token_hint' => $accessToken])->assertOk()->assertSee('logout_request', false);
        $this->assertAuthenticated();
    }

    public function test_the_confirmation_challenge_is_single_use_and_must_match(): void
    {
        $this->signedInWithIdToken();

        $page = $this->logout([])->assertOk();
        $challenge = $this->challengeFrom($page);

        $this->post('/oauth/logout/confirm', ['logout_request' => 'wrong', '_token' => session()->token()])->assertStatus(400);
        $this->assertAuthenticated();

        // The wrong attempt consumed the challenge.
        $this->post('/oauth/logout/confirm', ['logout_request' => $challenge, '_token' => session()->token()])->assertStatus(400);
        $this->assertAuthenticated();
    }

    public function test_an_expired_confirmation_is_refused(): void
    {
        $this->signedInWithIdToken();

        $challenge = $this->challengeFrom($this->logout([])->assertOk());
        $this->travel(11)->minutes();

        $this->post('/oauth/logout/confirm', ['logout_request' => $challenge, '_token' => session()->token()])->assertStatus(400);
        $this->assertAuthenticated();
    }

    public function test_an_unregistered_redirect_uri_is_never_followed(): void
    {
        [, , $idToken] = $this->signedInWithIdToken();

        $this->logout(['id_token_hint' => $idToken, 'post_logout_redirect_uri' => 'https://evil.test/'])
            ->assertStatus(400)
            ->assertSee('sign-out request cannot be completed');
        $this->assertAuthenticated();

        $this->logout(['id_token_hint' => $idToken, 'post_logout_redirect_uri' => 'https://rp.test/logged-out/extra'])->assertStatus(400);
        $this->assertAuthenticated();
    }

    public function test_a_redirect_uri_needs_a_client(): void
    {
        $this->signedInWithIdToken();

        $this->logout(['post_logout_redirect_uri' => 'https://rp.test/logged-out'])->assertStatus(400);
        $this->assertAuthenticated();
    }

    public function test_a_client_id_that_contradicts_the_hint_is_refused(): void
    {
        [, , $idToken] = $this->signedInWithIdToken();
        $other = $this->createConfidentialClient(['https://other.test/callback']);

        $this->logout(['id_token_hint' => $idToken, 'client_id' => (string) $other->getKey()])->assertStatus(400);
        $this->assertAuthenticated();
    }

    public function test_a_revoked_client_cannot_redirect(): void
    {
        [$client, , $idToken] = $this->signedInWithIdToken();
        $client->forceFill(['revoked' => true])->save();

        $this->logout(['id_token_hint' => $idToken, 'post_logout_redirect_uri' => 'https://rp.test/logged-out'])->assertStatus(400);
        $this->assertAuthenticated();
    }

    public function test_configured_logout_uris_replace_the_redirect_uris(): void
    {
        [$client, , $idToken] = $this->signedInWithIdToken();
        config(['openid-provider.logout.post_logout_redirect_uris' => [(string) $client->getKey() => ['https://rp.test/bye']]]);

        $this->logout(['id_token_hint' => $idToken, 'post_logout_redirect_uri' => 'https://rp.test/logged-out'])->assertStatus(400);
        $this->assertAuthenticated();

        $this->logout(['id_token_hint' => $idToken, 'post_logout_redirect_uri' => 'https://rp.test/bye'])->assertRedirect('https://rp.test/bye');
        $this->assertGuest();
    }

    public function test_signed_out_users_are_sent_on(): void
    {
        $client = $this->createConfidentialClient(['https://rp.test/logged-out']);

        $this->logout([
            'client_id' => (string) $client->getKey(),
            'post_logout_redirect_uri' => 'https://rp.test/logged-out',
            'state' => 'xyz',
        ])->assertRedirect('https://rp.test/logged-out?state=xyz');
    }

    public function test_head_requests_do_not_sign_out(): void
    {
        [, , $idToken] = $this->signedInWithIdToken();

        $this->call('HEAD', '/oauth/logout', ['id_token_hint' => $idToken])->assertOk();
        $this->assertAuthenticated();
    }

    public function test_logout_clears_the_recorded_authentication_time(): void
    {
        [, , $idToken] = $this->signedInWithIdToken();

        $this->logout(['id_token_hint' => $idToken]);

        $this->assertNull(session(LogoutController::SESSION_KEY));
        $this->assertNull(session('openid_provider.auth_time'));
    }
}
