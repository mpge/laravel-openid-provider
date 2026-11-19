<?php

namespace Mpge\OpenIdProvider\Tests\Feature;

use Mpge\OpenIdProvider\Tests\Concerns\InteractsWithOidc;
use Mpge\OpenIdProvider\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AuthorizationRequestValidationTest extends TestCase
{
    use InteractsWithOidc;

    /**
     * @return array<string, array{0: string}>
     */
    public static function untrustedRedirectUris(): array
    {
        return [
            'unregistered host' => ['https://evil.test/callback'],
            'prefix match' => ['https://rp.test/callback/extra'],
            'extra query' => ['https://rp.test/callback?next=https://evil.test'],
            'different scheme' => ['http://rp.test/callback'],
            'different port' => ['https://rp.test:8443/callback'],
            'trailing slash' => ['https://rp.test/callback/'],
            'case change' => ['https://rp.test/Callback'],
            'fragment' => ['https://rp.test/callback#x'],
        ];
    }

    #[DataProvider('untrustedRedirectUris')]
    public function test_unregistered_redirect_uri_is_shown_as_an_error_page_and_never_redirected(string $redirectUri): void
    {
        $this->signIn($this->createUser());
        $client = $this->createConfidentialClient();

        $response = $this->authorize($this->authorizationParams($client, ['redirect_uri' => $redirectUri]));

        $response->assertStatus(400);
        $this->assertNull($response->headers->get('Location'));
        $response->assertSee('redirect_uri is not registered');
    }

    public function test_missing_redirect_uri_is_an_error_page(): void
    {
        $client = $this->createConfidentialClient();
        $params = $this->authorizationParams($client);
        unset($params['redirect_uri']);

        $this->authorize($params)->assertStatus(400);
    }

    public function test_unknown_client_is_an_error_page(): void
    {
        $client = $this->createConfidentialClient();

        $this->authorize($this->authorizationParams($client, ['client_id' => '00000000-0000-0000-0000-000000000000']))
            ->assertStatus(400)
            ->assertSee('invalid_client');
    }

    public function test_redirect_uri_must_match_at_the_token_endpoint(): void
    {
        $client = $this->createConfidentialClient(['https://rp.test/callback', 'https://rp.test/other']);
        $this->skipConsentFor($client);
        $this->signIn($this->createUser());
        $code = $this->obtainCode($client);

        $this->exchangeCode($client, $code, ['redirect_uri' => 'https://rp.test/other'])
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_request']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedResponseTypes(): array
    {
        return [
            'implicit token' => ['token'],
            'implicit id_token' => ['id_token'],
            'implicit id_token token' => ['id_token token'],
            'hybrid code id_token' => ['code id_token'],
            'hybrid code token' => ['code token'],
            'hybrid code id_token token' => ['code id_token token'],
            'none' => ['none'],
        ];
    }

    #[DataProvider('refusedResponseTypes')]
    public function test_implicit_and_hybrid_flows_are_refused(string $responseType): void
    {
        $this->signIn($this->createUser());
        $client = $this->createConfidentialClient();

        $query = $this->redirectQuery($this->authorize($this->authorizationParams($client, ['response_type' => $responseType])));

        $this->assertSame('unsupported_response_type', $query['error']);
        $this->assertSame('state-123', $query['state']);
        $this->assertArrayNotHasKey('code', $query);
    }

    public function test_request_objects_are_refused(): void
    {
        $client = $this->createConfidentialClient();

        $this->assertSame('request_not_supported', $this->redirectQuery($this->authorize($this->authorizationParams($client, ['request' => 'eyJ...'])))['error']);
        $this->assertSame('request_uri_not_supported', $this->redirectQuery($this->authorize($this->authorizationParams($client, ['request_uri' => 'https://rp.test/r'])))['error']);
    }

    public function test_repeated_parameters_are_refused(): void
    {
        $client = $this->createConfidentialClient();
        $query = http_build_query($this->authorizationParams($client)).'&scope=openid';

        $redirect = $this->redirectQuery($this->get('/oauth/authorize?'.$query));

        $this->assertSame('invalid_request', $redirect['error']);
    }

    public function test_unknown_scopes_are_ignored_for_openid_requests(): void
    {
        $user = $this->createUser();
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($user);

        $tokens = $this->exchangeCode($client, $this->obtainCode($client, ['scope' => 'openid email made_up']))->assertOk()->json();

        $this->assertArrayHasKey('id_token', $tokens);
        $this->assertSame(['openid', 'email'], \Laravel\Passport\Passport::token()->newQuery()->firstOrFail()->scopes);
    }

    public function test_unknown_scopes_are_refused_for_plain_oauth_requests(): void
    {
        $client = $this->createConfidentialClient();

        $query = $this->redirectQuery($this->authorize($this->authorizationParams($client, ['scope' => 'email made_up'])));

        $this->assertSame('invalid_scope', $query['error']);
    }

    public function test_invalid_max_age_and_prompt_values_are_refused(): void
    {
        $client = $this->createConfidentialClient();

        $this->assertSame('invalid_request', $this->redirectQuery($this->authorize($this->authorizationParams($client, ['max_age' => '-1'])))['error']);
        $this->assertSame('invalid_request', $this->redirectQuery($this->authorize($this->authorizationParams($client, ['prompt' => 'none login'])))['error']);
        $this->assertSame('invalid_request', $this->redirectQuery($this->authorize($this->authorizationParams($client, ['prompt' => 'bogus'])))['error']);
    }

    public function test_client_without_the_authorization_code_grant_is_refused(): void
    {
        $client = $this->createConfidentialClient(grantTypes: ['client_credentials']);

        $this->assertSame('unauthorized_client', $this->redirectQuery($this->authorize($this->authorizationParams($client)))['error']);
    }
}
