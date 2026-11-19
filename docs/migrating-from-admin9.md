# Migrating from admin9/laravel-oidc-server 2.x

This guide moves an application from `admin9/laravel-oidc-server` 2.x to
`mpge/laravel-openid-provider` without any change on the client side: the
same issuer, the same endpoints, the same Passport clients and secrets, the
same `sub` values and the same signing key.

Both packages sit on Laravel Passport and keep their state in Passport's
tables, so the switch is a code and configuration change, not a data
migration.

## What stays the same

| Item | admin9 2.x | This package | Action |
| --- | --- | --- | --- |
| Issuer | `oidc-server.issuer` (`OIDC_ISSUER`, else `APP_URL`) | `openid-provider.issuer` (`OPENID_PROVIDER_ISSUER`, else `APP_URL`) | Set `OPENID_PROVIDER_ISSUER` to the exact current value |
| `GET, POST, DELETE /oauth/authorize` | `passport.authorizations.*` | same paths and names | none |
| `GET /oauth/authorize/continue` | `oidc.authorizations.continue` | same path and name | none |
| `POST /oauth/token` | `passport.token` | same | none |
| `GET, POST /oauth/userinfo` | `oidc.userinfo` | same | none |
| `POST /oauth/revoke` | `oidc.revoke` | same | none |
| `/.well-known/openid-configuration`, `/.well-known/jwks.json` | `oidc.discovery`, `oidc.jwks` | same | none |
| Clients, IDs, secrets, redirect URIs | Passport `oauth_clients` | same table | none |
| `sub` | `getOidcSubject()`, default `(string) $user->getKey()` | same method name and default | keep your override if you have one |
| Signing key | Passport private key, `kid` = first 16 hex characters of SHA-256 of the public key | Passport private key by default | keep the `kid` (below) |
| Token endpoint authentication | `client_secret_basic`, `client_secret_post` | same | none |
| PKCE | S256 | S256 | none |

## What is different

| admin9 2.x | This package |
| --- | --- |
| `POST /oauth/introspect` (RFC 7662), always on | Same path and route name, off by default: set `OPENID_PROVIDER_INTROSPECTION=true`. No `username` in the response. See [introspection.md](introspection.md). |
| `GET, POST /oauth/logout` (RP-initiated logout), always on | Same paths and route names, off by default: set `OPENID_PROVIDER_LOGOUT=true`. Expired hints are accepted; an unregistered `post_logout_redirect_uri` shows an error page instead of redirecting to `/`. See [logout.md](logout.md). |
| Authentication freshness kept in Redis | Kept in the session. No Redis requirement. |
| Host calls `AuthenticationRecorder::markAuthenticated()` after login | Every `Login` event on the configured guard records the time automatically. Call `AuthenticationTime::markAuthenticated()` only after a password or two-factor confirmation that does not log in again. |
| `ReauthenticationHandler::redirect(Request, ReauthenticationChallenge)` | `ReauthenticationHandler::handle(Request, ReauthenticationRequest)`; see [reauthentication.md](reauthentication.md) |
| Consent required for every client unless the client model says otherwise | Same by default, plus `consent.skip_for_clients` and `consent.remember_previous_consent` |
| ID token TTL `tokens.id_token_ttl` (900 seconds) | `id_token_ttl` (3600 seconds by default) |
| Access and refresh token TTLs in `tokens.*` | Set them on Passport (below) |

Check your clients first. If any of them calls `/oauth/introspect` or
`/oauth/logout`, enable that endpoint in step 3. Moodle's `auth_oauth2` calls
neither.

## Step by step

Do this on a staging copy first.

### 1. Record the current values

```bash
php artisan tinker --execute="echo config('oidc-server.issuer');"
php -r "echo substr(hash('sha256', file_get_contents('storage/oauth-public.key')), 0, 16), PHP_EOL;"
curl -s https://id.example.com/.well-known/jwks.json
```

The second command reproduces admin9's `kid`. If you supply the key through
`PASSPORT_PUBLIC_KEY` instead of a file, hash that exact string. Compare the
result with the `kid` in the JWKS output.

### 2. Swap the packages

```bash
composer remove admin9/laravel-oidc-server
composer require mpge/laravel-openid-provider
php artisan migrate
```

The migration adds `openid_provider_grants` and
`openid_provider_grant_tokens`. Passport's tables are not touched.

Remove leftovers of the old package: `config/oidc-server.php`, published
`resources/views/vendor/oidc-server` views, your `AuthenticationRecorder`
calls, and the old `ReauthenticationHandler` binding.

### 3. Configure

```dotenv
OPENID_PROVIDER_ISSUER=https://id.example.com      # exactly the old issuer
OPENID_PROVIDER_CURRENT_KID=0123456789abcdef       # the kid from step 1
OPENID_PROVIDER_ID_TOKEN_TTL=900                   # optional: admin9's default
```

Publish the configuration (`php artisan vendor:publish --tag=openid-provider-config`)
and translate the claim settings:

| admin9 2.x | This package |
| --- | --- |
| `scopes.profile.claims = ['name', 'nickname', 'picture', 'updated_at']` plus `default_claims_map` / `claims_resolver` | `scopes.profile.claims = ['name' => 'name', 'nickname' => 'nickname', 'picture' => [App\OpenId\Avatar::class, 'url'], 'updated_at' => UpdatedAt::class]` |
| `'email_verified' => [DefaultClaims::class, 'emailVerified']` | `'email_verified' => Mpge\OpenIdProvider\Claims\Resolvers\EmailVerified::class` |
| `'updated_at' => [DefaultClaims::class, 'updatedAt']` | `'updated_at' => Mpge\OpenIdProvider\Claims\Resolvers\UpdatedAt::class` |
| `user_model` | The user provider of `openid-provider.guard` |
| `client_model` | `Passport::useClientModel()` as usual |
| `ignore_passport_routes` | `routes.ignore_passport_routes` |
| `routes.token_middleware`, `routes.userinfo_middleware` | `routes.api_middleware` |
| `routes.authorization_middleware` | `routes.web_middleware` |
| `routes.discovery_middleware` | `routes.discovery_middleware` |
| `introspection_allowed_clients` | `introspection.allowed_clients` (same shape) |
| `post_logout_redirect_uris` | `logout.post_logout_redirect_uris` (same shape: client ID => URIs) |
| `post_logout_redirect_uris_supported`, `freshness` | No equivalent |

Make sure every claim a client maps today is still released by the same
scope, with the same value. Moodle, for instance, reads `given_name`,
`family_name` and `email` from UserInfo.

admin9 configured Passport's token lifetimes when `configure_passport` was
`true`. Keep them by setting them yourself, for example in
`AppServiceProvider::boot()`:

```php
use Laravel\Passport\Passport;

Passport::tokensExpireIn(now()->addSeconds(900));
Passport::refreshTokensExpireIn(now()->addSeconds(604800));
```

### 4. Update the user model

Replace admin9's contract and trait:

```php
// before
use Admin9\OidcServer\Concerns\HasOidcClaims;
use Admin9\OidcServer\Contracts\OidcUserInterface;

// after
use Mpge\OpenIdProvider\Concerns\HasOidcSubject;
use Mpge\OpenIdProvider\Contracts\OidcSubject;
```

If you had overridden `getOidcSubject()`, keep the method exactly as it is.
`sub` values must not change.

### 5. Re-authentication

If you bound an admin9 `ReauthenticationHandler`, port it to the new contract
(see [reauthentication.md](reauthentication.md)). The default handler signs
the user out and sends them to the login page, which works without any code.

### 6. Verify on staging

- `/.well-known/openid-configuration` has the same `issuer` and endpoint URLs.
  `introspection_endpoint` and `end_session_endpoint` are listed only if you
  enabled them.
- `/.well-known/jwks.json` lists the same `kid`, `n` and `e` as before.
- Sign in through each client. Accounts must be matched, not duplicated.
- UserInfo returns the same claims as before for the same token scopes.

### 7. Deploy

Deploy as usual. What users notice:

- Everyone is asked to sign in once more at their next authorization, because
  the package does not trust authentication times it did not record. Clients
  see an ordinary successful sign-in.
- Authorization codes issued in the seconds around the deploy may fail; the
  client simply starts a new sign-in.
- Refresh tokens issued by admin9 use league/oauth2-server's format with
  extra fields and are expected to keep working. ID tokens issued on such a
  refresh omit `auth_time` because it is unknown. Confirm this on staging with
  a real refresh token if your clients depend on refresh.

## Rotating to a new key later

Keeping the old key and `kid` is the zero-change path. When you want a key of
your own, follow [key-rotation.md](key-rotation.md); remove
`OPENID_PROVIDER_CURRENT_KID` right after `--promote`. The old `kid` stays
published as the previous key until you retire it.

## Rolling back

The package does not modify Passport's tables, so reinstalling admin9 and
restoring its configuration rolls back. Tokens issued in the meantime remain
valid Passport tokens. The two `openid_provider_*` tables can be dropped.
