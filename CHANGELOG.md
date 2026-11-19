# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Authorization endpoint for the authorization code flow only. Implicit and
  hybrid response types are refused with `unsupported_response_type`.
  Requests are accepted with GET and POST.
- PKCE with S256 only: required for public clients, optional for
  confidential clients, configurable to required for all.
- Exact `redirect_uri` matching. Unknown clients and unregistered redirect
  URIs are shown as an error page and never redirected to.
- `prompt=none`, `prompt=login`, `prompt=consent` and `max_age`, with
  `auth_time` tracked per session and the `ReauthenticationHandler`
  contract for an application's own confirmation screen.
- Consent screen (publishable view) that can be skipped per client or
  remembered from earlier grants.
- Token endpoint for `authorization_code`, `refresh_token` and
  `client_credentials`, with `client_secret_basic` and `client_secret_post`.
- RS256 ID tokens with `iss`, `sub`, `aud`, `exp`, `iat`, `auth_time` and
  `nonce`, optionally with scope claims.
- Single-use authorization codes (60 seconds by default). Reusing a code
  revokes every token issued from it.
- Refresh token rotation. Reusing a refresh token revokes the whole chain.
- `TokenReuseDetected` and `AuthorizationCodeIssued` events.
- UserInfo endpoint (GET and POST) returning only the claims of granted
  scopes, with `sub` always present.
- `OidcSubject` contract and `HasOidcSubject` trait; scope to claim mapping
  with attribute, class and method resolvers.
- Discovery document and JWKS with a `kid` on every key.
- Signing key rotation with current, next and previous keys and the
  `openid-provider:rotate-keys` command.
- Token revocation endpoint (RFC 7009).
- Route paths and names compatible with Passport and
  admin9/laravel-oidc-server, and a migration guide.
- Scripts that run the OpenID Foundation conformance suite (Basic OP and
  Config OP plans) and a Moodle sign-in smoke test against the demo app.
