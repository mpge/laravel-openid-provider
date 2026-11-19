# Installation

This guide takes a Laravel 12 or 13 application from nothing to a working
OpenID Connect provider.

## Requirements

- PHP 8.3 or newer with the `openssl` and `json` extensions
- Laravel 12 or 13
- Laravel Passport 13 (installed as a dependency)
- An `https` URL for the application in production. The issuer and every
  endpoint must be `https`; clients and the conformance suite check this.

## 1. Install the package

```bash
composer require mpge/laravel-openid-provider
```

The service provider `Mpge\OpenIdProvider\OpenIdProviderServiceProvider` is
auto-discovered.

## 2. Set up Passport

If Passport is not installed yet:

```bash
php artisan vendor:publish --tag=passport-migrations
php artisan migrate
php artisan passport:keys
```

`passport:keys` writes `storage/oauth-private.key` and
`storage/oauth-public.key`. Passport signs access tokens with them, and this
package signs ID tokens with the same private key until you rotate to a
dedicated key (see [key-rotation.md](key-rotation.md)). In containers you can
supply the keys through `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY`
instead of files.

Add a Passport guard for API requests in `config/auth.php` (UserInfo does not
use it, but Passport expects it):

```php
'guards' => [
    'web' => ['driver' => 'session', 'provider' => 'users'],
    'api' => ['driver' => 'passport', 'provider' => 'users'],
],
```

## 3. Run the package migrations

```bash
php artisan migrate
```

This creates `openid_provider_grants` and `openid_provider_grant_tokens`. They
link every access and refresh token to the authorization it came from, and
store the `nonce` and `auth_time` for the ID token. To copy the migrations into
your application instead, run
`php artisan vendor:publish --tag=openid-provider-migrations`.

## 4. Prepare the user model

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Mpge\OpenIdProvider\Concerns\HasOidcSubject;
use Mpge\OpenIdProvider\Contracts\OidcSubject;

class User extends Authenticatable implements OAuthenticatable, OidcSubject
{
    use HasApiTokens, HasOidcSubject;
}
```

`HasOidcSubject` returns the primary key as a string. Relying parties key
accounts on this value, so it must never change and never be reused. If your
primary keys could change (for example after a data migration), return an
immutable column instead:

```php
public function getOidcSubject(): string
{
    return $this->public_id;
}
```

## 5. Configure the issuer

```dotenv
OPENID_PROVIDER_ISSUER=https://id.example.com
```

The issuer must be the root URL of the application, with no trailing slash,
query or fragment. Discovery is served at
`https://id.example.com/.well-known/openid-configuration`. If you do not set
it, `APP_URL` is used.

Publish the configuration file if you want to change scopes, claims or other
settings:

```bash
php artisan vendor:publish --tag=openid-provider-config
```

Every key is described in [configuration.md](configuration.md).

## 6. Make sure there is a login page

Users who are not signed in are redirected to the route named `login` (change
it with `OPENID_PROVIDER_LOGIN_ROUTE`). After they sign in, Laravel's
`redirect()->intended()` sends them back to the authorization request. Laravel
Breeze, Jetstream and Fortify already behave this way.

The package records the time of every login on the configured guard
(`OPENID_PROVIDER_GUARD`, default `web`) and uses it for `auth_time`,
`max_age` and `prompt=login`. Users who were already signed in before the
package was installed have no recorded time, so they are asked to sign in
again once. See [reauthentication.md](reauthentication.md).

## 7. Create a client and test

```bash
php artisan passport:client --name="My app" --redirect_uri="https://app.example.com/callback"
```

Then open:

```
https://id.example.com/oauth/authorize?response_type=code&client_id=CLIENT_ID&redirect_uri=https%3A%2F%2Fapp.example.com%2Fcallback&scope=openid%20profile%20email&state=xyz&nonce=abc
```

See [clients-and-pkce.md](clients-and-pkce.md) for the token exchange.

## Routes and Passport

The package calls `Passport::ignoreRoutes()` and registers its own
`/oauth/authorize` and `/oauth/token` with the same paths and route names as
Passport. Passport's other routes (device authorization, token refresh for
first-party JavaScript, the optional JSON API) are therefore not registered.
If you need them, set `routes.ignore_passport_routes` to `false` and register
this package's routes after Passport's so they take precedence, or register
the Passport routes you need yourself.

To change paths or middleware, publish the routes file
(`php artisan vendor:publish --tag=openid-provider-routes`), set
`routes.enabled` to `false`, and load `routes/openid-provider.php` from your
own route files.

### CSRF and POST authorization requests

OpenID Connect requires the authorization endpoint to accept `POST`. The
`POST /oauth/authorize` route is therefore excluded from Laravel's CSRF
middleware (`ValidateCsrfToken` on Laravel 12, `PreventRequestForgery` on
Laravel 13). The consent form posts to the same route and its CSRF token is
checked by the controller instead. If your application replaces the CSRF
middleware with its own subclass, add `oauth/authorize` to its `$except` list.
