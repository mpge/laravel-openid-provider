<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\User;

/**
 * Wires the demo app used by `composer serve`, the conformance run and the
 * Moodle smoke test. Not part of the package.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            'auth.providers.users.model' => User::class,
            'auth.guards.api' => ['driver' => 'passport', 'provider' => 'users'],
            // The conformance suite sends many requests from one address.
            'openid-provider.routes.api_middleware' => [],
            'openid-provider.consent.skip_for_clients' => array_values(array_filter(explode(',', (string) env('DEMO_SKIP_CONSENT_CLIENTS', '')))),
        ]);
    }

    public function boot(): void
    {
        // The demo runs behind a TLS proxy; generate https URLs for its host.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceRootUrl((string) config('app.url'));
            URL::forceScheme('https');
        }
    }
}
