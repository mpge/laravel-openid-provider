<?php

namespace Mpge\OpenIdProvider\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Mpge\OpenIdProvider\Tests\Concerns\InteractsWithOidc;
use Mpge\OpenIdProvider\Tests\TestCase;

class PruneCommandTest extends TestCase
{
    use InteractsWithOidc;

    private function issueTokens(): void
    {
        $client = $this->createConfidentialClient();
        $this->skipConsentFor($client);
        $this->signIn($this->createUser());
        $this->exchangeCode($client, $this->obtainCode($client))->assertOk();
    }

    /**
     * What `passport:purge` leaves behind once every token and code expired.
     */
    private function purgePassportTables(): void
    {
        Passport::refreshToken()->newQuery()->delete();
        Passport::token()->newQuery()->delete();
        Passport::authCode()->newQuery()->delete();
    }

    public function test_rows_whose_tokens_and_codes_were_purged_are_removed(): void
    {
        $this->issueTokens();
        $this->purgePassportTables();
        $this->travel(8)->days();

        $this->artisan('openid-provider:prune')
            ->expectsOutputToContain('Removed 1 grants and 1 token links.')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('openid_provider_grants')->count());
        $this->assertSame(0, DB::table('openid_provider_grant_tokens')->count());
    }

    public function test_recent_rows_are_kept(): void
    {
        $this->issueTokens();
        $this->purgePassportTables();
        $this->travel(2)->days();

        $this->artisan('openid-provider:prune')->assertSuccessful();

        $this->assertSame(1, DB::table('openid_provider_grants')->count());
        $this->assertSame(1, DB::table('openid_provider_grant_tokens')->count());

        $this->artisan('openid-provider:prune', ['--hours' => '24'])->assertSuccessful();

        $this->assertSame(0, DB::table('openid_provider_grants')->count());
    }

    public function test_grants_with_live_tokens_or_codes_are_kept(): void
    {
        $this->issueTokens();
        $this->travel(30)->days();

        $this->artisan('openid-provider:prune')->assertSuccessful();
        $this->assertSame(1, DB::table('openid_provider_grants')->count());
        $this->assertSame(1, DB::table('openid_provider_grant_tokens')->count());

        // The tokens are purged but the code row is still there.
        Passport::refreshToken()->newQuery()->delete();
        Passport::token()->newQuery()->delete();

        $this->artisan('openid-provider:prune')->assertSuccessful();
        $this->assertSame(1, DB::table('openid_provider_grants')->count());
        $this->assertSame(0, DB::table('openid_provider_grant_tokens')->count());
    }

    public function test_hours_must_be_a_whole_number(): void
    {
        $this->artisan('openid-provider:prune', ['--hours' => 'soon'])->assertFailed();
    }
}
