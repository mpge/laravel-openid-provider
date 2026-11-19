# Token introspection

`POST /oauth/introspect` implements
[RFC 7662](https://datatracker.ietf.org/doc/html/rfc7662). A resource server
sends a token and learns whether it is active, and for whom. It is off by
default.

```dotenv
OPENID_PROVIDER_INTROSPECTION=true
```

When it is on, discovery lists `introspection_endpoint` and
`introspection_endpoint_auth_methods_supported`. With the setting off, the
route does not exist.

## Who can call it

Only confidential clients, authenticated with `client_secret_basic` or
`client_secret_post`. Public clients get `401 invalid_client`.

A client sees its own tokens only. To let a resource server inspect tokens
issued to other clients, list them for it:

```php
// config/openid-provider.php
'introspection' => [
    'enabled' => env('OPENID_PROVIDER_INTROSPECTION', false),
    'allowed_clients' => [
        // caller client ID => token owner client IDs
        '0199a1b2-api-resource-server' => ['0199a1b2-web-app', '0199a1b2-mobile-app'],
    ],
],
```

This only widens introspection. It never lets the resource server revoke
another client's tokens.

## Request

```http
POST /oauth/introspect HTTP/1.1
Authorization: Basic base64(client_id:client_secret)
Content-Type: application/x-www-form-urlencoded

token=eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...&token_type_hint=access_token
```

`token_type_hint` (`access_token` or `refresh_token`) only decides which lookup
runs first. A wrong hint still finds the token.

## Responses

An active access token:

```json
{
  "active": true,
  "scope": "openid profile email",
  "client_id": "0199a1b2-web-app",
  "token_type": "Bearer",
  "exp": 1763600000,
  "iat": 1763596400,
  "aud": "0199a1b2-web-app",
  "iss": "https://id.example.com",
  "sub": "42"
}
```

`sub` is the user's OpenID subject, the same value as in ID tokens and
UserInfo. It is absent for `client_credentials` tokens, which have no user.

An active refresh token:

```json
{
  "active": true,
  "client_id": "0199a1b2-web-app",
  "token_type": "refresh_token",
  "exp": 1764201200,
  "iss": "https://id.example.com",
  "sub": "42",
  "scope": "openid profile email"
}
```

Everything else gets `{"active": false}` with status 200. That includes
unknown, malformed, expired and revoked tokens, a refresh token that was
already used, tokens of a revoked client or a deleted user, and tokens the
caller is not allowed to see. The response never says which of these applied.

Errors:

| Status | `error` | When |
| --- | --- | --- |
| 400 | `invalid_request` | `token` is missing, or the request uses two client authentication methods at once |
| 401 | `invalid_client` | Missing or wrong credentials, or a public client |

Responses carry `Cache-Control: no-store`. The route uses
`routes.api_middleware` (by default `throttle:60,1`).

## Compared with admin9/laravel-oidc-server

The path, route name (`oidc.introspect`), authentication rules and the
`allowed_clients` map (admin9's `introspection_allowed_clients`) behave the
same. The response does not include admin9's `username` claim; read profile
data from UserInfo instead.
