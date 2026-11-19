# Clients and PKCE

Clients are Passport clients. Nothing about them is specific to this package,
so existing Passport clients, IDs and secrets keep working.

## Confidential clients

A confidential client has a secret and runs on a server: Moodle, a Laravel
app using Socialite, a backend service.

```bash
php artisan passport:client --name="Moodle" --redirect_uri="https://moodle.example.com/admin/oauth2callback.php"
```

Or in code:

```php
use Laravel\Passport\ClientRepository;

$client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
    name: 'Moodle',
    redirectUris: ['https://moodle.example.com/admin/oauth2callback.php'],
    confidential: true,
);

$client->getKey();      // client_id
$client->plainSecret;   // client_secret, only available right after creation
```

Passport stores only a hash of the secret. Copy it when the client is created.

Confidential clients authenticate at the token endpoint with either:

- `client_secret_basic`: HTTP Basic with the client ID and secret, or
- `client_secret_post`: `client_id` and `client_secret` in the form body.

Sending credentials both ways in one request is refused (RFC 6749 section
2.3). Secrets are verified with Laravel's hasher (`password_verify`), which
compares in constant time.

PKCE is optional for confidential clients, because some (Moodle among them)
do not support it. Set `pkce.required_for_confidential_clients` to `true` when
all your clients send it.

## Public clients

A public client cannot keep a secret: single-page apps, mobile and desktop
apps.

```bash
php artisan passport:client --public --name="Mobile app" --redirect_uri="com.example.app:/oauth/callback"
```

Public clients must use PKCE with `S256`. Requests without a `code_challenge`
are refused with `invalid_request`, and `code_challenge_method=plain` (or a
missing method, which means `plain`) is refused too.

```text
code_verifier  = 43 to 128 characters from [A-Za-z0-9-._~]
code_challenge = BASE64URL(SHA256(code_verifier))
```

At the token endpoint the client sends `client_id` and `code_verifier`, no
secret. A missing verifier gives `invalid_request`; a wrong one gives
`invalid_grant`. Sending a `code_verifier` for a code that was issued without
a challenge is refused as well, which blocks PKCE downgrade attacks.

## Redirect URIs

`redirect_uri` is required and must equal one of the client's registered URIs
exactly, byte for byte. There is no prefix, wildcard, trailing slash, case or
port leniency (not even for loopback addresses). An unknown client or an
unregistered `redirect_uri` is shown to the user as an error page and is never
redirected to, so the authorization endpoint cannot be used as an open
redirector.

Register every URI the client uses, for example both
`https://app.example.com/callback` and `https://staging.example.com/callback`.

## The flow, step by step

1. The client sends the user to:

   ```
   GET /oauth/authorize?response_type=code
       &client_id=CLIENT_ID
       &redirect_uri=https%3A%2F%2Fapp.example.com%2Fcallback
       &scope=openid%20profile%20email
       &state=RANDOM_STATE
       &nonce=RANDOM_NONCE
       [&code_challenge=...&code_challenge_method=S256]
       [&prompt=none|login|consent]
       [&max_age=SECONDS]
   ```

   The same parameters can be sent as a form `POST`.

2. The user signs in (if needed), re-authenticates (if `prompt=login` or
   `max_age` asks for it) and approves the consent screen (unless consent can
   be skipped).

3. The provider redirects to
   `https://app.example.com/callback?code=CODE&state=RANDOM_STATE`. `state` is
   returned exactly as sent.

4. The client exchanges the code within 60 seconds:

   ```bash
   curl -u CLIENT_ID:CLIENT_SECRET https://id.example.com/oauth/token \
     -d grant_type=authorization_code \
     -d code=CODE \
     -d redirect_uri=https://app.example.com/callback
   ```

   The response contains `access_token`, `refresh_token`, `expires_in`,
   `token_type` and, when `openid` was granted, `id_token`.

5. The client reads claims from UserInfo:

   ```bash
   curl -H "Authorization: Bearer ACCESS_TOKEN" https://id.example.com/oauth/userinfo
   ```

## Codes and refresh tokens

- Authorization codes expire after `authorization_code_ttl` seconds (60 by
  default) and work once. Presenting a code again is refused with
  `invalid_grant`, and every access and refresh token issued from it is
  revoked (RFC 6749 section 4.1.2).
- Each refresh returns a new refresh token and revokes the old one, together
  with the access token it belonged to. Presenting a refresh token that was
  already used revokes the whole chain: every access and refresh token that
  descends from the original authorization.
- A refresh can request the same scopes or fewer, never more
  (`invalid_scope`).
- Only the client the code or refresh token was issued to can redeem it. A
  request from another client, or with wrong credentials, is refused without
  consuming the token.

## Client credentials

`client_credentials` is available to confidential clients whose Passport
`grant_types` include it. No user is involved, so no ID token is issued.

## Revoking a client

Set the Passport client's `revoked` column to `true`. From then on the
authorization endpoint shows an error page, the token endpoint answers
`invalid_client`, and UserInfo rejects the client's existing access tokens.

## Token revocation

Clients revoke tokens at `POST /oauth/revoke` (RFC 7009), authenticating the
same way as at the token endpoint:

```bash
curl -u CLIENT_ID:CLIENT_SECRET https://id.example.com/oauth/revoke \
  -d token=REFRESH_TOKEN -d token_type_hint=refresh_token
```

The answer is always `200` with an empty body once the client is
authenticated, whether or not the token existed or belonged to that client.
Revoking a refresh token revokes the whole authorization it belongs to.
Revoking an access token revokes only that token.
