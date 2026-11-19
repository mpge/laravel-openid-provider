# Configuration reference

All settings live in `config/openid-provider.php`. Publish it with
`php artisan vendor:publish --tag=openid-provider-config`. Settings that can be
set from the environment list the variable name.

Resolvers are referenced by attribute name or class name rather than closures
so the configuration can be cached with `php artisan config:cache`.

## `issuer`

Environment: `OPENID_PROVIDER_ISSUER` (falls back to `APP_URL`).

The issuer identifier. It must be an absolute URL with no query or fragment,
and it must use `https` in production (plain `http` is only accepted so local
development works). A trailing slash is removed. It appears as `iss` in every ID token
and as `issuer` in discovery, and every endpoint URL in discovery is built
from it. Never change it once clients are configured: clients reject tokens
whose `iss` does not match exactly.

## `guard`

Environment: `OPENID_PROVIDER_GUARD`. Default: `web`.

The session guard end users sign in with. The authorization endpoint checks
this guard, `auth_time` is recorded for logins on it, and users referenced by
tokens are loaded through its user provider.

## `routes`

| Key | Default | Description |
| --- | --- | --- |
| `routes.enabled` | `true` | Register the package routes. Set to `false` when you load a published copy of `routes/openid-provider.php` yourself. |
| `routes.ignore_passport_routes` | `true` | Call `Passport::ignoreRoutes()`. The package replaces `/oauth/authorize` and `/oauth/token`. |
| `routes.prefix` | `oauth` | Prefix for `authorize`, `token`, `userinfo` and `revoke`. Keep `oauth` for compatibility with Passport and admin9 clients. |
| `routes.web_middleware` | `['web']` | Middleware for the browser-facing authorization endpoint. It needs sessions. |
| `routes.api_middleware` | `['throttle:60,1']` | Middleware for the token, UserInfo and revocation endpoints. They must not use sessions or CSRF. |
| `routes.discovery_middleware` | `[]` | Middleware for discovery and JWKS. |

## `login_route`

Environment: `OPENID_PROVIDER_LOGIN_ROUTE`. Default: `login`.

Where unauthenticated users are sent: a route name, or a path when no route has
that name. The resume URL is stored as Laravel's intended URL, so the login
controller must finish with `redirect()->intended()`.

## `grant_types`

Default: `['authorization_code', 'refresh_token', 'client_credentials']`.

Grant types the token endpoint accepts. Anything else, including Passport
grants such as `password` or the device code grant, is refused with
`unsupported_grant_type`. Discovery lists the values from this setting.

## `authorization_code_ttl`

Environment: `OPENID_PROVIDER_CODE_TTL`. Default: `60`.

Lifetime of authorization codes in seconds. Codes are single use regardless.

## `id_token_ttl`

Environment: `OPENID_PROVIDER_ID_TOKEN_TTL`. Default: `3600`.

Lifetime of ID tokens in seconds. It also tells you how long to keep a
retired signing key published after a rotation.

Access and refresh token lifetimes are Passport settings:
`Passport::tokensExpireIn()`, `Passport::refreshTokensExpireIn()` and
`Passport::clientCredentialsTokensExpireIn()`.

## `pending_authorization_ttl`

Default: `1800`.

How long an authorization request waiting for the user (login,
re-authentication or consent) stays valid in the session, in seconds.

## `pkce`

| Key | Default | Description |
| --- | --- | --- |
| `pkce.required_for_confidential_clients` | `false` (`OPENID_PROVIDER_PKCE_REQUIRED`) | Require PKCE from confidential clients too. Public clients always need it. Only `S256` is accepted. |

## `consent`

| Key | Default | Description |
| --- | --- | --- |
| `consent.view` | `openid-provider::consent` | Blade view for the consent screen. |
| `consent.skip_for_clients` | `[]` | Client IDs that never see the consent screen (first-party applications). |
| `consent.remember_previous_consent` | `true` | Skip the screen when the user already holds an active, unrevoked token for the same client covering every requested scope. |

A client model can also skip consent by overriding Passport's
`skipsAuthorization(Authenticatable $user, array $scopes): bool`.
`prompt=consent` always shows the screen.

## `error_view`

Default: `openid-provider::error`.

Blade view for errors that must not be redirected to the client, such as an
unknown `client_id` or an unregistered `redirect_uri`.

## `id_token`

| Key | Default | Description |
| --- | --- | --- |
| `id_token.include_scope_claims` | `false` | Also put the claims of the granted scopes into the ID token. They are always available from UserInfo. |

## `keys`

| Key | Default | Description |
| --- | --- | --- |
| `keys.directory` | `storage_path('openid-provider')` (`OPENID_PROVIDER_KEYS_PATH`) | Holds `current.key`, `next.key` and `previous.key`. When `current.key` does not exist the Passport private key signs ID tokens. |
| `keys.current_kid` | `null` (`OPENID_PROVIDER_CURRENT_KID`) | Override the `kid` of the current key, for example to keep the `kid` another package published. `null` uses the RFC 7638 thumbprint. |
| `keys.bits` | `3072` | Size of keys generated by `openid-provider:rotate-keys`. Minimum 2048. |
| `keys.jwks_max_age` | `3600` | `Cache-Control: max-age` of the JWKS response, in seconds. Wait at least this long between `--prepare` and `--promote`. |

## `scopes`

A map of scope name to a description (shown on the consent screen) and the
claims the scope releases:

```php
'scopes' => [
    'profile' => [
        'description' => 'Your name and basic profile details.',
        'claims' => [
            'name' => 'name',
            'given_name' => 'given_name',
            'family_name' => 'family_name',
            'preferred_username' => 'username',
            'updated_at' => Mpge\OpenIdProvider\Claims\Resolvers\UpdatedAt::class,
        ],
    ],
    'email' => [
        'description' => 'Your email address.',
        'claims' => [
            'email' => 'email',
            'email_verified' => Mpge\OpenIdProvider\Claims\Resolvers\EmailVerified::class,
        ],
    ],
],
```

A resolver is one of:

- an attribute name, read with `data_get($user, $name)` (so nested paths such
  as `profile.locale` work);
- a class implementing `Mpge\OpenIdProvider\Contracts\ClaimResolver`, resolved
  from the container;
- a `[ClassName::class, 'method']` pair, called with the user and claim name.

Claims that resolve to `null` are left out. Protocol claims (`iss`, `sub`,
`aud`, `exp`, `iat`, `nbf`, `jti`, `auth_time`, `nonce`, `azp`, `at_hash`,
`c_hash`, `acr`, `amr`) can never be set by a mapping. `openid` is always
registered and releases only `sub`. Every scope here is registered with
Passport; descriptions you register yourself with `Passport::tokensCan()`
take precedence. See [scopes-and-claims.md](scopes-and-claims.md).

## `default_scopes`

Default: `[]`.

Scopes used when an authorization request has no `scope` parameter.

## `reauthentication`

| Key | Default | Description |
| --- | --- | --- |
| `reauthentication.handler` | `Mpge\OpenIdProvider\Authentication\RedirectToLoginHandler` | Used when the application has not bound `Mpge\OpenIdProvider\Contracts\ReauthenticationHandler` itself. The default signs the user out and sends them to the login page. |

See [reauthentication.md](reauthentication.md).

## `introspection`

| Key | Default | Description |
| --- | --- | --- |
| `introspection.enabled` | `false` (`OPENID_PROVIDER_INTROSPECTION`) | Register `POST /oauth/introspect` (RFC 7662) and list it in discovery. |
| `introspection.allowed_clients` | `[]` | Resource servers that may introspect other clients' tokens: caller client ID => list of token owner client IDs. Every client can always introspect its own tokens. |

See [introspection.md](introspection.md).

## `logout`

| Key | Default | Description |
| --- | --- | --- |
| `logout.enabled` | `false` (`OPENID_PROVIDER_LOGOUT`) | Register `GET, POST /oauth/logout` and `POST /oauth/logout/confirm` (RP-initiated logout) and list `end_session_endpoint` in discovery. |
| `logout.post_logout_redirect_uris` | `[]` | Client ID => exact URIs allowed as `post_logout_redirect_uri`. Clients not listed may use their registered redirect URIs. |
| `logout.redirect_to` | `/` | Route name or path the browser goes to after a logout without `post_logout_redirect_uri`. |
| `logout.view` | `openid-provider::logout` | Blade view asking the user to confirm a logout that could not be verified. |

See [logout.md](logout.md).
