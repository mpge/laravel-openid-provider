# Moodle setup

Moodle's `auth_oauth2` plugin was the first client this package was built
for. Moodle reads discovery once when the service is saved, uses the
authorization code flow as a confidential client without PKCE, exchanges
the code and then reads all user details from UserInfo. It never reads the ID
token and does not call a logout endpoint.

The values below were used in the smoke test (`moodle-smoke/`, Moodle 4.5
LTS). Replace `id.example.com` and `moodle.example.com` with your hosts.

## 1. On the provider: create the client

```bash
php artisan passport:client \
  --name="Moodle" \
  --redirect_uri="https://moodle.example.com/admin/oauth2callback.php"
```

Copy the client ID and secret. The redirect URI must be exactly
`{Moodle wwwroot}/admin/oauth2callback.php`.

Optionally skip the consent screen for Moodle, as a first-party application,
in `config/openid-provider.php`:

```php
'consent' => [
    'skip_for_clients' => ['CLIENT_ID'],
    // ...
],
```

## 2. On the provider: release the claims Moodle needs

Moodle needs at least a first name, last name and email address to create an
account, and reads them from UserInfo with the `profile` and `email` scopes:

| Claim | Moodle field | Scope |
| --- | --- | --- |
| `given_name` | First name | `profile` |
| `family_name` | Last name | `profile` |
| `email` | Email address | `email` |
| `sub` | Username (see step 4) | always |

Make sure `given_name` and `family_name` resolve for your users. The default
configuration reads attributes with those names; if your `users` table only
has `name`, point the claims at your own resolver (see
[scopes-and-claims.md](scopes-and-claims.md)). Optional extras Moodle maps
automatically: `middle_name`, `nickname`, `picture`, `address`, `phone`,
`locale`.

## 3. In Moodle: create the OAuth 2 service

Site administration > Server > OAuth 2 services > **Custom**:

| Field | Value |
| --- | --- |
| Name | `Example ID` (shown on the login button) |
| Client ID | the Passport client ID |
| Client secret | the Passport client secret |
| Authenticate token requests via HTTP headers | either. Unticked sends `client_secret_post`, ticked sends `client_secret_basic`. |
| Scopes included in a login request | `openid profile email` |
| Scopes included in a login request for offline access | `openid profile email` |
| Additional parameters included in a login request | leave empty |
| Additional parameters included in a login request for offline access | leave empty |
| Service base URL | `https://id.example.com` (the issuer, no trailing slash) |
| Login domains | leave empty, or your email domains to restrict sign-in |
| Logo URL | optional |
| This service will be used | `Login page and internal services` (or `Login page only`) |
| Name displayed on the login page | optional |
| Require email verification | `No` when the provider already verifies email addresses; `Yes` makes Moodle send its own confirmation email to new accounts |

Save. Moodle fetches `https://id.example.com/.well-known/openid-configuration`
and creates the endpoints (`authorization_endpoint`, `token_endpoint`,
`userinfo_endpoint`, `discovery_endpoint`) and default user field mappings.
Check them with the **Configure endpoints** and **Configure user field
mappings** icons.

If saving fails with "Could not discover end points", Moodle could not reach
the provider. On private networks, check Site administration > General >
Security > HTTP security: `cURL blocked hosts list` and `cURL allowed ports
list`.

## 4. In Moodle: key accounts on `sub`

By default Moodle uses the email address as the account's username, so a user
who changes email would get a second Moodle account. Map the stable subject
instead. In **Configure user field mappings**, add:

| External field name | Internal field name |
| --- | --- |
| `sub` | `username` |

The defaults created from discovery stay as they are (`given_name` to
`firstname`, `family_name` to `lastname`, `email` to `email`, and so on).

Moodle lower-cases usernames. The package's default subject is the numeric
primary key, which is unaffected. If you override `getOidcSubject()`, return
lower-case values or values without letters.

## 5. In Moodle: enable OAuth 2 sign-in

Site administration > Plugins > Authentication > Manage authentication:
enable **OAuth 2**.

## 6. Test

Log out of Moodle, open its login page and click the new button. The first
sign-in creates a Moodle account (auth method `oauth2`, username = `sub`);
every later sign-in, from any browser, logs in to the same account.

## Smoke test result

Latest run (2026-11-19, Moodle 4.5 LTS): **pass**. Moodle created the
service from discovery, the first sign-in created one account (auth `oauth2`,
username `1` = `sub`, first name, last name and email from UserInfo), and a
second sign-in from a fresh browser logged in to the same account. Moodle
sent `client_secret_post` without PKCE, exchanged the code and called
UserInfo; it never requested the ID token's contents or a logout endpoint.
Full output:
[`moodle-smoke/results/2026-11-19-result.txt`](../moodle-smoke/results/2026-11-19-result.txt).

## Automated smoke test

`moodle-smoke/run.sh` builds this exact setup in Docker (Moodle 4.5 LTS on
PHP 8.3, PostgreSQL 16, the demo provider), signs in twice with fresh
browsers and checks that one account was created and then reused. It needs
Docker and at least 6 GB of free memory, and removes everything when done.

```bash
composer install
./moodle-smoke/run.sh
cat moodle-smoke/.runtime/result.txt
```

The service is created through Moodle's own API
(`core\oauth2\api::create_issuer`, which performs discovery like the admin
form) in `moodle-smoke/setup-oauth2.php`.

Moodle only accepts `https` service base URLs, so the stack serves the
provider through a Caddy TLS proxy as `https://op.openid.test` and adds the
proxy's private root certificate to Moodle's CA bundle
(`moodledata/moodleorgca.crt`). It also clears Moodle's cURL blocked hosts
and allowed ports, because the provider lives on a private Docker network.
