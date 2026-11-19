<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Laravel\Passport\Passport;
use Workbench\App\Models\User;

/**
 * Seeds the demo provider: one user and the clients described by the
 * DEMO_CLIENTS environment variable.
 *
 * DEMO_CLIENTS is a semicolon separated list of
 * "id|secret|name|redirect_uri[,redirect_uri...]" entries. Fixed IDs and
 * secrets let the conformance suite and Moodle be configured in advance.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(['email' => env('DEMO_USER_EMAIL', 'ada@example.com')], [
            'name' => 'Ada Lovelace',
            'given_name' => 'Ada',
            'family_name' => 'Lovelace',
            'username' => 'ada',
            'email_verified_at' => now(),
            'password' => env('DEMO_USER_PASSWORD', 'correct-horse-battery'),
        ]);

        foreach (array_filter(explode(';', (string) env('DEMO_CLIENTS', ''))) as $definition) {
            [$id, $secret, $name, $redirects] = array_pad(explode('|', $definition), 4, '');

            Passport::client()->newQuery()->updateOrCreate(['id' => $id], [
                'name' => $name,
                'secret' => $secret,
                'redirect_uris' => array_values(array_filter(explode(',', $redirects))),
                'grant_types' => ['authorization_code', 'refresh_token', 'client_credentials'],
                'revoked' => false,
            ]);
        }
    }
}
