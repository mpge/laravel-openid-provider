<?php

namespace Mpge\OpenIdProvider;

use DateInterval;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Bridge\AuthCodeRepository as PassportAuthCodeRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository as PassportRefreshTokenRepository;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use Mpge\OpenIdProvider\Authentication\AuthenticationTime;
use Mpge\OpenIdProvider\Bridge\AuthCodeRepository;
use Mpge\OpenIdProvider\Bridge\RefreshTokenRepository;
use Mpge\OpenIdProvider\Claims\ClaimsResolver;
use Mpge\OpenIdProvider\Console\PruneCommand;
use Mpge\OpenIdProvider\Console\RotateKeysCommand;
use Mpge\OpenIdProvider\Contracts\ReauthenticationHandler;
use Mpge\OpenIdProvider\Grants\GrantRepository;
use Mpge\OpenIdProvider\Keys\KeyStore;
use Mpge\OpenIdProvider\Tokens\IdTokenResponse;
use Mpge\OpenIdProvider\Tokens\IssuanceContext;

/**
 * Registers the OpenID Connect provider on top of Laravel Passport.
 *
 * Auto-discovered through composer.json. It:
 *  - makes Passport's authorization server return ID tokens, use the
 *    configured authorization code lifetime and always rotate refresh tokens;
 *  - registers the protocol routes (replacing Passport's own routes);
 *  - records auth_time on every login on the configured guard;
 *  - links every issued access token to its grant, so reuse of a code or
 *    refresh token can revoke the whole family.
 */
class OpenIdProviderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/openid-provider.php', 'openid-provider');

        $this->app->scoped(IssuanceContext::class);
        $this->app->singleton(KeyStore::class);
        $this->app->singleton(ClaimsResolver::class);
        $this->app->singleton(AuthenticationTime::class);

        $this->app->bind(PassportAuthCodeRepository::class, AuthCodeRepository::class);
        $this->app->bind(PassportRefreshTokenRepository::class, RefreshTokenRepository::class);

        $this->app->bindIf(ReauthenticationHandler::class, function (Application $app): ReauthenticationHandler {
            /** @var class-string<ReauthenticationHandler> $handler */
            $handler = config('openid-provider.reauthentication.handler');

            return $app->make($handler);
        });

        Passport::useAuthorizationServerResponseType(new IdTokenResponse);

        if (config('openid-provider.routes.ignore_passport_routes', true) === true) {
            Passport::ignoreRoutes();
        }

        $this->app->extend(AuthorizationServer::class, function (AuthorizationServer $server, Application $app): AuthorizationServer {
            $ttl = max(1, (int) config('openid-provider.authorization_code_ttl', 60));

            $grant = new AuthCodeGrant(
                $app->make(PassportAuthCodeRepository::class),
                $app->make(PassportRefreshTokenRepository::class),
                new DateInterval("PT{$ttl}S"),
            );
            $grant->setRefreshTokenTTL(Passport::refreshTokensExpireIn());

            // Replaces the grant Passport enabled, keeping its access token TTL.
            $server->enableGrantType($grant, Passport::tokensExpireIn());

            // Refresh tokens are single use: each use revokes the old one.
            $server->revokeRefreshTokens(true);

            return $server;
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'openid-provider');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (config('openid-provider.routes.enabled', true) === true) {
            $this->loadRoutesFrom(__DIR__.'/../routes/openid-provider.php');
        }

        $this->registerScopes();
        $this->registerListeners();

        if ($this->app->runningInConsole()) {
            $this->commands([RotateKeysCommand::class, PruneCommand::class]);

            $this->publishes([
                __DIR__.'/../config/openid-provider.php' => config_path('openid-provider.php'),
            ], 'openid-provider-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/openid-provider'),
            ], 'openid-provider-views');

            $this->publishes([
                __DIR__.'/../routes/openid-provider.php' => base_path('routes/openid-provider.php'),
            ], 'openid-provider-routes');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'openid-provider-migrations');
        }
    }

    /**
     * Register `openid` and the configured scopes with Passport, after the
     * application has registered its own (its descriptions win).
     */
    private function registerScopes(): void
    {
        $this->app->booted(function (): void {
            $scopes = ['openid' => 'Sign you in with your account.'];

            foreach ((array) config('openid-provider.scopes', []) as $scope => $definition) {
                $scopes[(string) $scope] = is_array($definition) && is_string($definition['description'] ?? null)
                    ? $definition['description']
                    : (string) $scope;
            }

            Passport::tokensCan(array_merge($scopes, Passport::$scopes));
        });
    }

    private function registerListeners(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if ($event->guard !== config('openid-provider.guard', 'web') || ! $this->app->bound('session.store')) {
                return;
            }

            // Restoring a session from a "remember me" cookie is not an
            // authentication, so it must not refresh auth_time.
            $guard = $this->app->make('auth')->guard($event->guard);

            if ($guard instanceof SessionGuard && $guard->viaRemember()) {
                return;
            }

            /** @var Session $session */
            $session = $this->app->make('session.store');
            $this->app->make(AuthenticationTime::class)->markAuthenticated($session, $event->user);
        });

        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->guard === config('openid-provider.guard', 'web') && $this->app->bound('session.store')) {
                /** @var Session $session */
                $session = $this->app->make('session.store');
                $this->app->make(AuthenticationTime::class)->forget($session);
            }
        });

        Event::listen(AccessTokenCreated::class, function (AccessTokenCreated $event): void {
            $grant = $this->app->make(IssuanceContext::class)->grant;

            if ($grant !== null) {
                $this->app->make(GrantRepository::class)->attachAccessToken($grant->id, $event->tokenId);
            }
        });
    }
}
