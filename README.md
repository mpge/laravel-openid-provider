# Laravel OpenID Provider

[![tests](https://github.com/mpge/laravel-openid-provider/actions/workflows/tests.yml/badge.svg)](https://github.com/mpge/laravel-openid-provider/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

Turn a Laravel application into an OpenID Connect provider, built on
[Laravel Passport](https://laravel.com/docs/passport).

The package does a deliberately small part of OpenID Connect and does it to the
spec: the authorization code flow, ID tokens, UserInfo, discovery, JWKS with
key rotation, and token revocation. It is checked against the OpenID
Foundation conformance suite instead of asking you to trust it. See
[docs/conformance.md](docs/conformance.md).

- PHP 8.3+, Laravel 12 and 13, Passport 13, league/oauth2-server 9
- No hand-written cryptography: signing and encryption go through
  league/oauth2-server, Passport and lcobucci/jwt
- Fails closed: anything that cannot be validated is refused

## What is supported

| Feature | Status |
| --- | --- |
| Authorization code flow (`response_type=code`) | Supported |
| PKCE | S256 only. Required for public clients, optional for confidential clients (configurable) |
| Implicit and hybrid flows | Refused with `unsupported_response_type` |
| Grants at the token endpoint | `authorization_code`, `refresh_token`, `client_credentials` |
| Client authentication | `client_secret_basic`, `client_secret_post` |
| ID tokens | RS256, with `iss`, `sub`, `aud`, `exp`, `iat`, `auth_time`, `nonce` |
| Refresh tokens | Rotated on every use; reuse revokes the whole chain |
| Authorization code reuse | Refused, and every token issued from the code is revoked |
| UserInfo (`GET` and `POST`) | Claims of the granted scopes only, `sub` always present |
| Discovery and JWKS | `/.well-known/openid-configuration`, `/.well-known/jwks.json` |
| Signing key rotation | Two published keys at once, `openid-provider:rotate-keys` |
| `prompt=none`, `prompt=login`, `prompt=consent`, `max_age` | Supported, with a re-authentication contract |
| Token revocation (RFC 7009) | Supported |
| Dynamic client registration, request objects, encrypted ID tokens, pairwise subjects, front and back-channel logout, RP-initiated logout, token introspection | Not supported |

## Installation

```bash
composer require mpge/laravel-openid-provider
php artisan vendor:publish --tag=passport-migrations
php artisan migrate
php artisan passport:keys
```

The service provider is auto-discovered. It registers the package migrations
(two small tables that track which tokens came from which authorization) and
the routes below. Set the issuer, which must be the public `https` URL of the
application:

```dotenv
OPENID_PROVIDER_ISSUER=https://id.example.com
```

Make the user model implement the subject contract. The default subject is the
primary key as a string; it must never change for a user.

```php
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Mpge\OpenIdProvider\Concerns\HasOidcSubject;
use Mpge\OpenIdProvider\Contracts\OidcSubject;

class User extends Authenticatable implements OAuthenticatable, OidcSubject
{
    use HasApiTokens, HasOidcSubject;
}
```

Full steps, including the `api` guard and the login route, are in
[docs/installation.md](docs/installation.md).

### Endpoints

| Path | Route name |
| --- | --- |
| `GET, POST /oauth/authorize` | `passport.authorizations.authorize`, `passport.authorizations.approve` |
| `DELETE /oauth/authorize` | `passport.authorizations.deny` |
| `POST /oauth/token` | `passport.token` |
| `GET, POST /oauth/userinfo` | `oidc.userinfo` |
| `POST /oauth/revoke` | `oidc.revoke` |
| `GET /.well-known/openid-configuration` | `oidc.discovery` |
| `GET /.well-known/jwks.json` | `oidc.jwks` |

## Configure a client

Clients are ordinary Passport clients. Create a confidential client for a
server-side application:

```bash
php artisan passport:client --name="Moodle" --redirect_uri="https://moodle.example.com/admin/oauth2callback.php"
```

Give the client its ID, its secret and your issuer URL. Most clients only need
the issuer: they read everything else from discovery. Redirect URIs are
compared exactly, character for character. Public clients (no secret) must use
PKCE with S256. See [docs/clients-and-pkce.md](docs/clients-and-pkce.md), and
[docs/moodle.md](docs/moodle.md) for a complete Moodle setup.

## Add a custom scope

Scopes map to claims in `config/openid-provider.php`. A claim's resolver is an
attribute name, a class implementing `ClaimResolver`, or a
`[Class::class, 'method']` pair:

```php
'scopes' => [
    // ...
    'acme_access' => [
        'description' => 'Your Acme roles.',
        'claims' => [
            'acme_roles' => App\OpenId\AcmeRoles::class,
            'acme_employee_id' => 'employee_number',
        ],
    ],
],
```

```php
namespace App\OpenId;

use Illuminate\Contracts\Auth\Authenticatable;
use Mpge\OpenIdProvider\Contracts\ClaimResolver;

class AcmeRoles implements ClaimResolver
{
    public function resolve(Authenticatable $user, string $claim): array
    {
        return $user->roles()->pluck('name')->all();
    }
}
```

The scope is registered with Passport and listed in discovery automatically.
UserInfo returns `acme_roles` only to clients that were granted `acme_access`.
See [docs/scopes-and-claims.md](docs/scopes-and-claims.md).

## Rotate signing keys

```bash
php artisan openid-provider:rotate-keys --prepare   # publish the next key
# wait for clients to refresh their JWKS cache (keys.jwks_max_age)
php artisan openid-provider:rotate-keys --promote   # sign with it; the old key stays published
# wait for ID tokens signed by the old key to expire (id_token_ttl)
php artisan openid-provider:rotate-keys --retire    # stop publishing the old key
```

Run the command without options to see the current keys. See
[docs/key-rotation.md](docs/key-rotation.md).

## Documentation

- [Installation](docs/installation.md)
- [Configuration reference](docs/configuration.md)
- [Clients and PKCE](docs/clients-and-pkce.md)
- [Scopes and claims](docs/scopes-and-claims.md)
- [Re-authentication](docs/reauthentication.md)
- [Key rotation runbook](docs/key-rotation.md)
- [Security model and threat notes](docs/security.md)
- [Moodle setup](docs/moodle.md)
- [Conformance results](docs/conformance.md)
- [Migrating from admin9/laravel-oidc-server](docs/migrating-from-admin9.md)
- [Upgrading](docs/upgrading.md)

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md). Please report vulnerabilities
privately as described in [SECURITY.md](SECURITY.md), not in public issues.

## License

MIT. See [LICENSE.md](LICENSE.md).
