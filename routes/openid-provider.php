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
use Mpge\OpenIdProvider\Http\Controllers\JwksController;
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
        Route::post('authorize', [AuthorizationController::class, 'approve'])
            ->name('passport.authorizations.approve');
        Route::delete('authorize', [AuthorizationController::class, 'deny'])
            ->name('passport.authorizations.deny');
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
    });

Route::middleware((array) config('openid-provider.routes.discovery_middleware', []))
    ->group(function (): void {
        Route::get('.well-known/openid-configuration', DiscoveryController::class)
            ->name('oidc.discovery');
        Route::get('.well-known/jwks.json', JwksController::class)
            ->name('oidc.jwks');
    });
