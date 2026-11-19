<?php

namespace Mpge\OpenIdProvider\Tests\Feature;

use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Mpge\OpenIdProvider\Keys\KeyStore;
use Mpge\OpenIdProvider\Tests\Concerns\InteractsWithOidc;
use Mpge\OpenIdProvider\Tests\TestCase;

class AuthorizationCodeFlowTest extends TestCase
{
    use InteractsWithOidc;

    public function test_confidential_client_without_pkce_completes_the_flow_and_gets_a_signed_id_token(): void
    {
        $user = $this->createUser();
        $client = $this->createConfidentialClient();
        $authTime = time() - 30;
        $this->signIn($user, $authTime);

        $code = $this->obtainCode($client);
        $response = $this->exchangeCode($client, $code)->assertOk();

        $response->assertJsonStructure(['token_type', 'expires_in', 'access_token', 'refresh_token', 'id_token']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $idToken = $this->parseJwt($response->json('id_token'));
        $claims = $idToken->claims();

        $this->assertSame('https://op.test', $claims->get('iss'));
        $this->assertSame((string) $user->getKey(), $claims->get('sub'));
        $this->assertSame([(string) $client->getKey()], $claims->get('aud'));
        $this->assertSame('nonce-456', $claims->get('nonce'));
        $this->assertSame($authTime, $claims->get('auth_time'));
        $this->assertFalse($claims->has('azp'));
        $this->assertFalse($claims->has('email'), 'Scope claims stay out of the ID token unless enabled.');
        $this->assertSame('RS256', $idToken->headers()->get('alg'));

        $key = app(KeyStore::class)->current();
        $this->assertSame($key->kid, $idToken->headers()->get('kid'));
        $this->assertTrue((new Validator)->validate($idToken, new SignedWith(new Sha256, InMemory::plainText($key->publicKeyPem()))));
    }

    public function test_state_is_returned_byte_for_byte(): void
    {
        $user = $this->createUser();
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($user);

        $state = "a b+c/d=e&f%20g\u{00e9}~";
        $response = $this->authorize($this->authorizationParams($client, ['state' => $state]));

        $this->assertSame($state, $this->redirectQuery($response)['state']);
    }

    public function test_scope_claims_are_added_to_the_id_token_when_enabled(): void
    {
        config(['openid-provider.id_token.include_scope_claims' => true]);
        $user = $this->createUser();
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($user);

        $tokens = $this->exchangeCode($client, $this->obtainCode($client, ['scope' => 'openid email']))->json();
        $claims = $this->parseJwt($tokens['id_token'])->claims();

        $this->assertSame($user->email, $claims->get('email'));
        $this->assertTrue($claims->get('email_verified'));
        $this->assertFalse($claims->has('name'), 'profile was not requested.');
    }

    public function test_no_id_token_without_the_openid_scope(): void
    {
        $user = $this->createUser();
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($user);

        $tokens = $this->exchangeCode($client, $this->obtainCode($client, ['scope' => 'email']))->assertOk()->json();

        $this->assertArrayNotHasKey('id_token', $tokens);
    }

    public function test_public_client_with_s256_pkce_completes_the_flow(): void
    {
        $user = $this->createUser();
        $client = $this->createPublicClient();
        $this->skipConsentFor($client);
        $this->signIn($user);

        $verifier = str_repeat('v', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $code = $this->obtainCode($client, ['code_challenge' => $challenge, 'code_challenge_method' => 'S256']);

        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $client->redirect_uris[0],
            'client_id' => (string) $client->getKey(),
            'code_verifier' => $verifier,
        ])->assertOk()->assertJsonStructure(['access_token', 'id_token']);
    }

    public function test_client_secret_basic_authentication_works(): void
    {
        $user = $this->createUser();
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($user);
        $code = $this->obtainCode($client);

        $this->withHeader('Authorization', 'Basic '.base64_encode($client->getKey().':'.$client->plainSecret))
            ->post('/oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $client->redirect_uris[0],
            ])->assertOk()->assertJsonStructure(['id_token']);
    }

    public function test_consent_screen_is_shown_and_denial_returns_access_denied(): void
    {
        $user = $this->createUser();
        $client = $this->createConfidentialClient();
        $this->signIn($user);

        $page = $this->authorize($this->authorizationParams($client))->assertOk();
        $page->assertSee('Demo RP');
        $token = $this->pendingAuthToken($page);

        $query = $this->redirectQuery($this->delete('/oauth/authorize', ['auth_token' => $token]));

        $this->assertSame('access_denied', $query['error']);
        $this->assertSame('state-123', $query['state']);
    }

    public function test_previous_consent_is_remembered_but_prompt_consent_asks_again(): void
    {
        $user = $this->createUser();
        $client = $this->createConfidentialClient();
        $this->signIn($user);

        $this->exchangeCode($client, $this->obtainCode($client))->assertOk();

        $this->authorize($this->authorizationParams($client))->assertRedirect();
        $this->authorize($this->authorizationParams($client, ['prompt' => 'consent']))->assertOk();
    }

    public function test_unauthenticated_user_is_sent_to_login_and_resumes_afterwards(): void
    {
        $user = $this->createUser(['password' => 'secret-password']);
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);

        $this->authorize($this->authorizationParams($client))->assertRedirect('/login');

        $afterLogin = $this->post('/login', ['email' => $user->email, 'password' => 'secret-password']);
        $resumeUrl = (string) $afterLogin->headers->get('Location');
        $this->assertStringContainsString('/oauth/authorize/continue?request=', $resumeUrl);

        $query = $this->redirectQuery($this->get($resumeUrl));
        $this->assertArrayHasKey('code', $query);

        $idToken = $this->parseJwt($this->exchangeCode($client, $query['code'])->json('id_token'));
        $this->assertEqualsWithDelta(time(), $idToken->claims()->get('auth_time'), 5);
    }
}
