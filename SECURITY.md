# Security policy

## Supported versions

| Version | Supported |
| --- | --- |
| 0.x (latest minor) | Yes |

Until 1.0, security fixes are released for the latest minor version only.

## Reporting a vulnerability

Please do not open a public issue, discussion or pull request for a
security problem.

Report it privately through GitHub's private vulnerability reporting:

1. Go to the repository's **Security** tab.
2. Choose **Report a vulnerability**
   (<https://github.com/mpge/laravel-openid-provider/security/advisories/new>).
3. Describe the issue, the affected versions, and how to reproduce it. A
   failing test or a minimal Laravel app is the most helpful.

You can expect an acknowledgement within five working days. We will keep you
updated while a fix is prepared, agree a disclosure date with you, and credit
you in the advisory unless you prefer otherwise.

## Scope

In scope: anything in this package that weakens the guarantees described in
[docs/security.md](docs/security.md), for example token or code replay,
redirect URI bypasses, claim leakage across scopes, signature or key handling
problems, CSRF on the consent flow, or secrets reaching logs.

Out of scope: vulnerabilities in Laravel, Passport, league/oauth2-server or
lcobucci/jwt themselves (please report those upstream), and problems that need
an already compromised server or application key.
