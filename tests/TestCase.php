<?php

namespace Mpge\OpenIdProvider\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\PassportServiceProvider;
use Mpge\OpenIdProvider\OpenIdProviderServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use phpseclib4\Crypt\RSA;
use Workbench\App\Http\Controllers\LoginController;
use Workbench\App\Models\User;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected static ?string $passportKeys = null;

    protected string $keyDirectory;

    protected function getPackageProviders($app): array
    {
        return [PassportServiceProvider::class, OpenIdProviderServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        Passport::$validateKeyPermissions = false;
        Passport::loadKeysFrom(self::passportKeyDirectory());

        $this->keyDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'openid-provider-tests-'.Str::random(8);

        tap($app['config'], function (Repository $config): void {
            $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
            $config->set('app.url', 'https://op.test');
            $config->set('database.default', 'testing');
            $config->set('auth.providers.users.model', User::class);
            $config->set('auth.guards.api', ['driver' => 'passport', 'provider' => 'users']);
            $config->set('openid-provider.issuer', 'https://op.test');
            $config->set('openid-provider.keys.directory', $this->keyDirectory);
            $config->set('openid-provider.routes.api_middleware', []);
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/laravel/passport/database/migrations');
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(function (): void {
            Route::get('/login', [LoginController::class, 'show'])->name('login');
            Route::post('/login', [LoginController::class, 'login']);
        });
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->keyDirectory);

        parent::tearDown();
    }

    /**
     * One Passport key pair for the whole run (key generation is slow).
     */
    protected static function passportKeyDirectory(): string
    {
        if (self::$passportKeys === null) {
            $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'openid-provider-tests-passport-'.getmypid();

            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }

            if (! file_exists($directory.'/oauth-private.key')) {
                $key = RSA::createKey(2048);
                file_put_contents($directory.'/oauth-private.key', (string) $key);
                file_put_contents($directory.'/oauth-public.key', (string) $key->getPublicKey());
            }

            self::$passportKeys = $directory;
        }

        return self::$passportKeys;
    }

    protected function createConfidentialClient(array $redirectUris = ['https://rp.test/callback'], array $grantTypes = ['authorization_code', 'refresh_token']): Client
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Demo RP', $redirectUris, true);

        if ($grantTypes !== ['authorization_code', 'refresh_token']) {
            $client->forceFill(['grant_types' => $grantTypes])->save();
        }

        return $client;
    }

    protected function createPublicClient(array $redirectUris = ['https://spa.test/callback']): Client
    {
        return app(ClientRepository::class)->createAuthorizationCodeGrantClient('Public SPA', $redirectUris, false);
    }

    protected function createUser(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }
}
