<?php

namespace Mpge\OpenIdProvider\Tests\Feature;

use Illuminate\Http\Request;
use Mpge\OpenIdProvider\Authentication\AuthenticationTime;
use Mpge\OpenIdProvider\Authentication\ReauthenticationRequest;
use Mpge\OpenIdProvider\Contracts\ReauthenticationHandler;
use Mpge\OpenIdProvider\Tests\Concerns\InteractsWithOidc;
use Mpge\OpenIdProvider\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

class ReauthenticationTest extends TestCase
{
    use InteractsWithOidc;

    public function test_prompt_none_without_a_session_returns_login_required(): void
    {
        $client = $this->createConfidentialClient();

        $response = $this->authorize($this->authorizationParams($client, ['prompt' => 'none']));
        $query = $this->redirectQuery($response);

        $this->assertStringStartsWith('https://rp.test/callback?', (string) $response->headers->get('Location'));
        $this->assertSame('login_required', $query['error']);
        $this->assertSame('state-123', $query['state']);
    }

    public function test_prompt_none_without_consent_returns_consent_required(): void
    {
        $client = $this->createConfidentialClient();
        $this->signIn($this->createUser());

        $query = $this->redirectQuery($this->authorize($this->authorizationParams($client, ['prompt' => 'none'])));

        $this->assertSame('consent_required', $query['error']);
    }

    public function test_prompt_none_with_session_and_consent_returns_a_code_without_ui(): void
    {
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($this->createUser());

        $this->assertArrayHasKey('code', $this->redirectQuery($this->authorize($this->authorizationParams($client, ['prompt' => 'none']))));
    }

    public function test_prompt_none_with_stale_authentication_and_max_age_returns_login_required(): void
    {
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($this->createUser(), time() - 600);

        $query = $this->redirectQuery($this->authorize($this->authorizationParams($client, ['prompt' => 'none', 'max_age' => '60'])));

        $this->assertSame('login_required', $query['error']);
    }

    public function test_max_age_forces_a_new_login_and_the_new_auth_time_is_in_the_id_token(): void
    {
        $user = $this->createUser(['password' => 'secret-password']);
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($user, time() - 600);

        $this->authorize($this->authorizationParams($client, ['max_age' => '60']))->assertRedirect('/login');
        $this->assertGuest();

        $resumeUrl = (string) $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])->headers->get('Location');
        $code = $this->redirectQuery($this->get($resumeUrl))['code'];

        $authTime = $this->parseJwt($this->exchangeCode($client, $code)->json('id_token'))->claims()->get('auth_time');
        $this->assertGreaterThanOrEqual(time() - 5, $authTime);
    }

    public function test_recent_authentication_satisfies_max_age(): void
    {
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($this->createUser(), time() - 10);

        $this->assertArrayHasKey('code', $this->redirectQuery($this->authorize($this->authorizationParams($client, ['max_age' => '60']))));
    }

    public function test_prompt_login_forces_a_new_login_even_with_a_fresh_session(): void
    {
        $user = $this->createUser(['password' => 'secret-password']);
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($user, time() - 1);

        $this->authorize($this->authorizationParams($client, ['prompt' => 'login']))->assertRedirect('/login');

        $resumeUrl = (string) $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])->headers->get('Location');

        // Logging in once satisfies prompt=login; resuming does not loop.
        $this->assertArrayHasKey('code', $this->redirectQuery($this->get($resumeUrl)));
    }

    public function test_unknown_auth_time_requires_reauthentication(): void
    {
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->actingAs($this->createUser());

        $this->authorize($this->authorizationParams($client))->assertRedirect('/login');
    }

    public function test_application_can_bind_its_own_reauthentication_handler(): void
    {
        $this->app->bind(ReauthenticationHandler::class, fn () => new class implements ReauthenticationHandler
        {
            public function handle(Request $request, ReauthenticationRequest $reauthentication): Response
            {
                $request->session()->put('resume', $reauthentication->resumeUrl);

                return response('confirm-password:'.$reauthentication->reason.':'.$reauthentication->clientName);
            }
        });

        $user = $this->createUser();
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($user, time() - 600);

        $this->authorize($this->authorizationParams($client, ['max_age' => '60']))
            ->assertOk()
            ->assertSee('confirm-password:max_age:Demo RP');

        // The app confirms the password, records it and resumes.
        app(AuthenticationTime::class)->markAuthenticated(app('session.store'), $user);
        $this->assertArrayHasKey('code', $this->redirectQuery($this->get((string) session('resume'))));
        $this->assertAuthenticatedAs($user);
    }

    public function test_auth_time_from_another_user_is_ignored(): void
    {
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $other = $this->createUser();
        $this->actingAs($this->createUser());
        $this->withSession([AuthenticationTime::SESSION_KEY => ['user' => (string) $other->getKey(), 'time' => time()]]);

        $this->authorize($this->authorizationParams($client))->assertRedirect('/login');
    }
}
