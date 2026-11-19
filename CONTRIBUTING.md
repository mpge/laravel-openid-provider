# Contributing

Thank you for helping. This package is intentionally small and strict, so
please read this before opening a pull request.

## Scope

The package implements a deliberately small part of OpenID Connect (see the
supported list in the [README](README.md)). New features need a clear use
case and must not weaken the existing guarantees. Features that are out of
scope on purpose (implicit and hybrid flows, dynamic client registration,
request objects, encrypted ID tokens, pairwise subjects) will not be merged.
Open an issue to discuss anything larger than a bug fix first.

Rules every change must follow:

- No hand-written cryptography or JWT code. Use league/oauth2-server,
  Passport or lcobucci/jwt.
- Runtime dependencies use stable version ranges only.
- Nothing specific to one company or product: extension points, not rules.
- Fail closed. If validation cannot complete, refuse the request.
- Never put secrets, keys, codes or tokens in logs, exceptions or events.
- No em dashes in documentation.

## Setup

```bash
git clone https://github.com/mpge/laravel-openid-provider.git
cd laravel-openid-provider
composer install
```

## Checks

All of these run in CI and must pass:

```bash
composer test       # PHPUnit
composer analyse    # PHPStan level 8
composer lint       # Pint (composer format to fix)
composer audit
```

Every behaviour change needs a feature test in `tests/Feature`. Security fixes
need a test that fails without the fix.

## Running the demo provider

```bash
composer serve
```

serves the workbench app (`workbench/`) on <http://127.0.0.1:8000>. Seed it
with clients through the `DEMO_CLIENTS` environment variable (see
`workbench/database/seeders/DatabaseSeeder.php`).

## Conformance and Moodle

Changes to protocol behaviour should be checked against the OpenID Foundation
conformance suite (`./conformance/run.sh`, see
[docs/conformance.md](docs/conformance.md)) and, when they affect sign-in,
the Moodle smoke test (`./moodle-smoke/run.sh`, see
[docs/moodle.md](docs/moodle.md)). Both need Docker and several GB of free
memory.

## Pull requests

- Keep commits small and focused, with messages in the imperative mood.
- Update the documentation in `docs/` and the `Unreleased` section of
  [CHANGELOG.md](CHANGELOG.md).
- Fill in the pull request template, including how you tested the change.

Security problems must be reported privately; see [SECURITY.md](SECURITY.md).
