# Security model and threat notes

This page explains what the package protects against, how, and what remains
the application's job. Report vulnerabilities privately as described in
[SECURITY.md](../SECURITY.md).

## Principles

- **Small surface.** Only the authorization code flow, the
  `authorization_code`, `refresh_token` and `client_credentials` grants,
  UserInfo, discovery, JWKS and revocation exist. Implicit and hybrid flows,
  request objects, dynamic registration and logout endpoints are absent, not
  disabled.
- **No home-made cryptography.** Tokens are signed, verified, encrypted and
  decrypted only by league/oauth2-server, Passport and lcobucci/jwt. The
  package's own code builds JSON Web Keys from OpenSSL key details and hashes
  them for the `kid`; it never signs or verifies anything itself.
- **Fail closed.** Validation that cannot complete refuses the request: an
  unreadable signing key is an error, not an unsigned token; an unknown
  authentication time means "re-authenticate", not "assume recent"; an
  authorization code that cannot be tied to its nonce is never sent to the
  client.
- **No secrets in logs.** Events carry identifiers only (client ID, user ID,
  grant ID), never codes or tokens. Parameters that hold secrets are marked
  `#[SensitiveParameter]` so they are redacted from stack traces, and
  `SigningKey` hides the private key from debug output.

## Threats and mitigations

### Authorization code interception and injection

- Codes live 60 seconds and are bound to the client, the `redirect_uri` and,
  when PKCE is used, the `code_challenge`.
- PKCE is mandatory for public clients and accepts only `S256`. A
  `code_verifier` for a code issued without a challenge is refused, which
  stops downgrade attacks.
- The `nonce` is stored with the code and copied into the ID token, so a
  client can detect a code injected into another user's session.

### Code and refresh token replay

The token endpoint claims a code or refresh token with a single atomic
`UPDATE ... WHERE revoked = false` before league/oauth2-server processes it.
Two concurrent requests cannot both succeed. When the claim fails because the
value was already used, every access and refresh token issued from the same
authorization is revoked and a `TokenReuseDetected` event is dispatched. Alert
on that event: it usually means a token leaked.

Only an authenticated client that owns the code or refresh token can claim
it. A request with the wrong client or wrong credentials is refused by
league/oauth2-server without consuming the token, so a third party cannot
burn other clients' tokens.

### Open redirects

`redirect_uri` must match a registered URI exactly. Until the client and
`redirect_uri` are verified, errors are shown as a page, never redirected.

### Cross-site request forgery

- Consent approval and denial require the session's CSRF token. The approve
  route accepts POST authorization requests without a token (OpenID Connect
  requires POST support), so the controller checks the consent form's token
  itself.
- A pending authorization can only be approved by the same signed-in user it
  was shown to, using a 40-character random identifier tied to the session.
- `state` is returned unchanged so clients can protect their own callback.

### Session fixation and stale sessions

`auth_time` is stored per session together with the user ID; it is ignored
for any other user. `prompt=login` requires an authentication after the
request arrived, and `max_age` is enforced against the recorded time.

### Clickjacking

The consent page is sent with `X-Frame-Options: DENY`. Add a
`Content-Security-Policy: frame-ancestors 'none'` header globally if your
application uses CSP.

### Client authentication

Secrets are hashed by Passport and checked with `password_verify`, which is
constant time. Mixing HTTP Basic and form credentials in one request is
refused. Revoked clients are refused at every endpoint, including UserInfo
for tokens issued before the revocation.

### Token revocation privacy

`/oauth/revoke` answers `200` with an empty body for any token once the
client has authenticated, so it cannot be used to test whether a token
exists or belongs to someone else. A client can only revoke its own tokens.

### Claim leakage

UserInfo releases only claims of scopes the token was granted, plus `sub`.
Scope mappings cannot override protocol claims such as `sub`, `iss` or `aud`.
Unknown scopes are dropped, never granted.

## What remains the application's job

- **Serve everything over HTTPS** and set the issuer to the `https` URL.
- **Rate limit** login, password confirmation and the token endpoint
  (`routes.api_middleware` defaults to `throttle:60,1`).
- **Protect the key files** (`storage/oauth-private.key`,
  `storage/openid-provider/*.key`): mode `0600`, excluded from backups that
  leave your control, never committed.
- **Keep `sub` stable.** Do not reuse primary keys, and override
  `getOidcSubject()` if they might change.
- **Review clients** before creating them; there is no dynamic registration.
- **Prune old rows** with `php artisan passport:purge`. Grant rows for purged
  tokens are harmless but can be removed with a scheduled query on
  `openid_provider_grants.created_at`.
- **Watch `TokenReuseDetected`** and investigate.

## Known limitations

- `at_hash` is not included in ID tokens. It is optional for the
  authorization code flow.
- Access tokens are Passport JWTs signed with the Passport key, which is not
  published in the JWKS after you rotate to a dedicated ID token key. Clients
  should treat access tokens as opaque and call UserInfo.
- Sessions must be shared between servers (database, Redis) because pending
  authorizations and `auth_time` live in the session.
