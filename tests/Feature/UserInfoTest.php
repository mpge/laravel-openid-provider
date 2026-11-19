<?php

namespace Mpge\OpenIdProvider\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Passport;
use Mpge\OpenIdProvider\Contracts\ClaimResolver;
use Mpge\OpenIdProvider\Tests\Concerns\InteractsWithOidc;
use Mpge\OpenIdProvider\Tests\TestCase;
use Workbench\App\Models\User;

class UserInfoTest extends TestCase
{
    use InteractsWithOidc;

    /**
     * @return array<string, mixed>
     */
    private function tokensFor(string $scope, ?User $user = null): array
    {
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($user ?? $this->createUser());

        return $this->exchangeCode($client, $this->obtainCode($client, ['scope' => $scope]))->assertOk()->json();
    }

    public function test_userinfo_returns_only_claims_of_granted_scopes(): void
    {
        $user = $this->createUser(['name' => 'Ada Lovelace', 'given_name' => 'Ada', 'family_name' => 'Lovelace']);
        $tokens = $this->tokensFor('openid email', $user);

        $claims = $this->withToken($tokens['access_token'])->get('/oauth/userinfo')->assertOk()->json();

        $this->assertSame(['sub', 'email', 'email_verified'], array_keys($claims));
        $this->assertSame((string) $user->getKey(), $claims['sub']);
        $this->assertSame($user->email, $claims['email']);
        $this->assertTrue($claims['email_verified']);
    }

    public function test_userinfo_with_profile_scope_carries_every_mapped_profile_claim(): void
    {
        $user = $this->createUser(['name' => 'Ada Lovelace', 'given_name' => 'Ada', 'family_name' => 'Lovelace', 'username' => 'ada']);
        $tokens = $this->tokensFor('openid profile email', $user);

        $claims = $this->withToken($tokens['access_token'])->post('/oauth/userinfo')->assertOk()->json();

        $this->assertSame('Ada Lovelace', $claims['name']);
        $this->assertSame('Ada', $claims['given_name']);
        $this->assertSame('Lovelace', $claims['family_name']);
        $this->assertSame('ada', $claims['preferred_username']);
        $this->assertSame($user->updated_at->getTimestamp(), $claims['updated_at']);
        $this->assertArrayHasKey('email', $claims);
    }

    public function test_openid_only_returns_just_sub(): void
    {
        $tokens = $this->tokensFor('openid');

        $this->assertSame(['sub'], array_keys($this->withToken($tokens['access_token'])->get('/oauth/userinfo')->json()));
    }

    public function test_access_token_in_the_post_body_is_accepted(): void
    {
        $tokens = $this->tokensFor('openid');

        $this->post('/oauth/userinfo', ['access_token' => $tokens['access_token']])->assertOk()->assertJsonStructure(['sub']);
    }

    public function test_token_without_openid_scope_is_refused(): void
    {
        $tokens = $this->tokensFor('email');

        $this->withToken($tokens['access_token'])->get('/oauth/userinfo')
            ->assertStatus(403)
            ->assertJson(['error' => 'insufficient_scope']);
    }

    public function test_missing_and_invalid_tokens_are_refused_with_a_bearer_challenge(): void
    {
        $this->get('/oauth/userinfo')->assertStatus(401)->assertHeader('WWW-Authenticate');
        $this->withToken('not-a-token')->get('/oauth/userinfo')->assertStatus(401)->assertJson(['error' => 'invalid_token']);
    }

    public function test_access_token_in_the_query_string_is_refused(): void
    {
        $tokens = $this->tokensFor('openid');

        $this->get('/oauth/userinfo?access_token='.$tokens['access_token'])->assertStatus(400);
    }

    public function test_custom_scope_with_class_and_attribute_resolvers(): void
    {
        config(['openid-provider.scopes.acme_access' => [
            'description' => 'Your Acme access level.',
            'claims' => [
                'acme_roles' => AcmeRolesResolver::class,
                'acme_username' => 'username',
            ],
        ]]);
        Passport::tokensCan(array_merge(Passport::$scopes, ['acme_access' => 'Your Acme access level.']));

        $user = $this->createUser(['username' => 'grace']);
        $tokens = $this->tokensFor('openid acme_access', $user);

        $claims = $this->withToken($tokens['access_token'])->get('/oauth/userinfo')->assertOk()->json();

        $this->assertSame(['editor', 'viewer'], $claims['acme_roles']);
        $this->assertSame('grace', $claims['acme_username']);
        $this->assertArrayNotHasKey('email', $claims);
    }

    public function test_scope_mappings_cannot_override_protocol_claims(): void
    {
        config(['openid-provider.scopes.email.claims.sub' => 'email']);
        $user = $this->createUser();
        $tokens = $this->tokensFor('openid email', $user);

        $this->assertSame((string) $user->getKey(), $this->withToken($tokens['access_token'])->get('/oauth/userinfo')->json('sub'));
    }
}

class AcmeRolesResolver implements ClaimResolver
{
    public function resolve(Authenticatable $user, string $claim): array
    {
        return ['editor', 'viewer'];
    }
}
