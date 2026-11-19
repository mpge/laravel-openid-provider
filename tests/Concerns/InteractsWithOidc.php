<?php

namespace Mpge\OpenIdProvider\Tests\Concerns;

use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Mpge\OpenIdProvider\Authentication\AuthenticationTime;
use Workbench\App\Models\User;

/**
 * Helpers that drive the authorization code flow in feature tests.
 */
trait InteractsWithOidc
{
    protected function signIn(User $user, ?int $authTime = null): void
    {
        $this->actingAs($user);
        $this->withSession([AuthenticationTime::SESSION_KEY => [
            'user' => (string) $user->getKey(),
            'time' => $authTime ?? time(),
        ]]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    protected function authorizationParams(Client $client, array $overrides = []): array
    {
        return array_merge([
            'response_type' => 'code',
            'client_id' => (string) $client->getKey(),
            'redirect_uri' => $client->redirect_uris[0],
            'scope' => 'openid profile email',
            'state' => 'state-123',
            'nonce' => 'nonce-456',
        ], $overrides);
    }

    /**
     * @param  array<string, string>  $params
     */
    protected function authorize(array $params): TestResponse
    {
        return $this->get('/oauth/authorize?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * Run the authorization request and approve consent, returning the code.
     *
     * @param  array<string, string>  $overrides
     */
    protected function obtainCode(Client $client, array $overrides = []): string
    {
        $response = $this->authorize($this->authorizationParams($client, $overrides));

        if ($response->getStatusCode() === 200) {
            $token = $this->pendingAuthToken($response);
            $response = $this->post('/oauth/authorize', ['auth_token' => $token]);
        }

        $query = $this->redirectQuery($response);
        $this->assertArrayHasKey('code', $query, 'No code in redirect: '.$response->headers->get('Location'));

        return $query['code'];
    }

    protected function pendingAuthToken(TestResponse $response): string
    {
        preg_match('/name="auth_token" value="([^"]+)"/', (string) $response->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null, 'Consent page has no auth_token.');

        return $matches[1];
    }

    /**
     * @return array<string, string>
     */
    protected function redirectQuery(TestResponse $response): array
    {
        $response->assertRedirect();
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * @param  array<string, string>  $extra
     */
    protected function exchangeCode(Client $client, string $code, array $extra = []): TestResponse
    {
        return $this->post('/oauth/token', array_merge([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $client->redirect_uris[0],
            'client_id' => (string) $client->getKey(),
            'client_secret' => (string) $client->plainSecret,
        ], array_filter($extra, fn ($value) => $value !== null)));
    }

    protected function refresh(Client $client, string $refreshToken, ?string $scope = null): TestResponse
    {
        return $this->post('/oauth/token', array_filter([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => (string) $client->getKey(),
            'client_secret' => (string) $client->plainSecret,
            'scope' => $scope,
        ], fn ($value) => $value !== null));
    }

    protected function parseJwt(string $jwt): Plain
    {
        $token = (new Parser(new JoseEncoder))->parse($jwt);
        $this->assertInstanceOf(Plain::class, $token);

        return $token;
    }

    protected function skipConsentFor(Client $client): void
    {
        config(['openid-provider.consent.skip_for_clients' => [(string) $client->getKey()]]);
    }
}
