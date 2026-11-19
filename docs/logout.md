# RP-initiated logout

`GET` and `POST /oauth/logout` implement
[OpenID Connect RP-Initiated Logout 1.0](https://openid.net/specs/openid-connect-rpinitiated-1_0.html).
A client sends the user here to end their session at the provider. It is off
by default.

```dotenv
OPENID_PROVIDER_LOGOUT=true
```

When it is on, discovery lists `end_session_endpoint`. With the setting off,
the routes do not exist.

## Request

```http
GET /oauth/logout?id_token_hint=eyJ...&post_logout_redirect_uri=https%3A%2F%2Fapp.example.com%2Flogged-out&state=af0ifjsldkj
```

| Parameter | Description |
| --- | --- |
| `id_token_hint` | An ID token this provider issued to the client. Recommended. |
| `client_id` | Identifies the client when no hint is sent. Must match the hint's audience when both are sent. |
| `post_logout_redirect_uri` | Where to send the browser afterwards. Needs `id_token_hint` or `client_id`. |
| `state` | Returned unchanged as a query parameter on `post_logout_redirect_uri`. |

## What happens

1. The hint is checked: its signature must verify against one of the
   published keys (current, next or previous) and `iss` must be the issuer.
   An expired hint is accepted, as the specification asks, because clients
   usually log out long after the ID token expired. An invalid hint is
   ignored, not refused.
2. The client must exist and not be revoked.
3. `post_logout_redirect_uri` must match an allowed URI for that client
   exactly. Otherwise an error page is shown, the user stays signed in, and
   nothing is redirected.
4. If nobody is signed in, the browser is sent on straight away.
5. If the hint's `sub` is the signed-in user's subject, the session ends and
   the browser is sent on.
6. Otherwise (no hint, an invalid hint, or a hint for a different user) a
   confirmation page asks the user whether to sign out. Its form posts to
   `POST /oauth/logout/confirm` with the CSRF token and a single-use
   challenge that expires after 10 minutes.

Ending the session calls `logout()` on the configured guard, invalidates the
session and regenerates the CSRF token, like Laravel's own logout. Laravel's
`Logout` event fires as usual, and the recorded `auth_time` is cleared.

`HEAD` requests never sign anyone out, so link previews and prefetchers are
harmless.

## Allowed redirect URIs

By default a client may use any of its registered redirect URIs. To give
clients dedicated logout URIs instead, list them; a client that is listed may
use only these:

```php
// config/openid-provider.php
'logout' => [
    'enabled' => env('OPENID_PROVIDER_LOGOUT', false),
    'post_logout_redirect_uris' => [
        '0199a1b2-web-app' => ['https://app.example.com/logged-out'],
    ],
    'redirect_to' => '/',
    'view' => 'openid-provider::logout',
],
```

Without `post_logout_redirect_uri`, the browser goes to `logout.redirect_to`,
a route name or a path (default `/`).

## The confirmation page

Publish the views (`php artisan vendor:publish --tag=openid-provider-views`)
and edit `logout.blade.php`, or point `logout.view` at your own view. It
receives:

| Variable | Description |
| --- | --- |
| `$client` | The `Laravel\Passport\Client`, or `null` when the request named none |
| `$clientName` | The client's name, or `null` |
| `$logoutRequest` | The challenge; post it back as `logout_request` |
| `$cancelUrl` | Where "stay signed in" should link to |

The form must post `logout_request` and `_token` to
`route('oidc.logout.confirm')`.

## CSRF

`/oauth/logout` itself is excluded from Laravel's CSRF middleware, because
clients send `POST` logout requests from their own pages. It only ends a
session without asking when a verified hint names the signed-in user. The
confirmation route keeps CSRF protection. If your application replaces the
CSRF middleware with its own subclass, add `oauth/logout` (not
`oauth/logout/confirm`) to its `$except` list.

## Not included

Front-channel and back-channel logout are not supported, so other clients
the user signed in to are not notified. Each client keeps its own session
until it expires or the user signs out there.

## Compared with admin9/laravel-oidc-server

The paths and route names (`oidc.logout`, `oidc.logout.confirm`) are the
same. Differences:

- admin9 needed an unexpired hint for logout without confirmation. Here an
  expired hint with a valid signature is accepted, as the specification
  recommends.
- admin9's global `post_logout_redirect_uris_supported` list has no
  equivalent. A redirect always needs a client.
- An unregistered `post_logout_redirect_uri` shows an error page. admin9
  redirected to `/`.
