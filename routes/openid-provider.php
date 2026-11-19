<?php

/*
|--------------------------------------------------------------------------
| OpenID Provider routes
|--------------------------------------------------------------------------
|
| Paths and names match Laravel Passport and admin9/laravel-oidc-server so
| clients and route() calls keep working. To customise them, publish this
| file (php artisan vendor:publish --tag=openid-provider-routes), set
| openid-provider.routes.enabled to false and load the published copy from
| your own route files.
|
*/

use Illuminate\Support\Facades\Route;
use Mpge\OpenIdProvider\Http\Controllers\AuthorizationController;
use Mpge\OpenIdProvider\Http\Controllers\DiscoveryController;
use Mpge\OpenIdProvider\Http\Controllers\IntrospectionController;
use Mpge\OpenIdProvider\Http\Controllers\JwksController;
use Mpge\OpenIdProvider\Http\Controllers\LogoutController;
use Mpge\OpenIdProvider\Http\Controllers\RevocationController;
use Mpge\OpenIdProvider\Http\Controllers\TokenController;
use Mpge\OpenIdProvider\Http\Controllers\UserInfoController;

$prefix = trim((string) config('openid-provider.routes.prefix', 'oauth'), '/');

Route::middleware((array) config('openid-provider.routes.web_middleware', ['web']))
    ->prefix($prefix)
    ->group(function (): void {
        Route::get('authorize', [AuthorizationController::class, 'authorize'])
            ->name('passport.authorizations.authorize');
        Route::get('authorize/continue', [AuthorizationController::class, 'resume'])
            ->name('oidc.authorizations.continue');
        // Also accepts authorization requests sent with POST, which arrive
        // without a CSRF token. The consent form's token is checked in the
        // controller instead.
        Route::post('authorize', [AuthorizationController::class, 'approve'])
            ->withoutMiddleware(array_filter([
                'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
                'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
                'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
            ], 'class_exists'))
            ->name('passport.authorizations.approve');
        Route::delete('authorize', [AuthorizationController::class, 'deny'])
            ->name('passport.authorizations.deny');

        if (config('openid-provider.logout.enabled', false) === true) {
            // Logout requests come from the client's pages without a CSRF
            // token. They only end the session directly with a verified
            // id_token_hint for the signed-in user; anything else needs the
            // CSRF-protected confirmation below.
            Route::match(['GET', 'POST'], 'logout', [LogoutController::class, 'logout'])
                ->withoutMiddleware(array_filter([
                    'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
                    'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
                    'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
                ], 'class_exists'))
                ->name('oidc.logout');
            Route::post('logout/confirm', [LogoutController::class, 'confirm'])
                ->name('oidc.logout.confirm');
        }
    });

Route::middleware((array) config('openid-provider.routes.api_middleware', []))
    ->prefix($prefix)
    ->group(function (): void {
        Route::post('token', [TokenController::class, 'issueToken'])
            ->name('passport.token');
        Route::match(['GET', 'POST'], 'userinfo', UserInfoController::class)
            ->name('oidc.userinfo');
        Route::post('revoke', RevocationController::class)
            ->name('oidc.revoke');

        if (config('openid-provider.introspection.enabled', false) === true) {
            Route::post('introspect', IntrospectionController::class)
                ->name('oidc.introspect');
        }
    });

Route::middleware((array) config('openid-provider.routes.discovery_middleware', []))
    ->group(function (): void {
        Route::get('.well-known/openid-configuration', DiscoveryController::class)
            ->name('oidc.discovery');
        Route::get('.well-known/jwks.json', JwksController::class)
            ->name('oidc.jwks');
    });
