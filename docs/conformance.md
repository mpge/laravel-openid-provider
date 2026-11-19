# Conformance results

The package is tested with the
[OpenID Foundation conformance suite](https://gitlab.com/openid/conformance-suite)
against the demo provider in `workbench/`, using the two plans the project
targets: **Basic OP** and **Config OP**.

## Latest run

| | |
| --- | --- |
| Date | 2026-11-19 |
| Package commit | `159392c` |
| Suite version | `release-v5.3.4` |
| Provider | workbench demo app, PHP 8.4, Laravel 13, Passport 13, behind TLS |
| Plans | `oidcc-basic-certification-test-plan[server_metadata=discovery][client_registration=static_client]`, `oidcc-config-certification-test-plan` |
| Outcome | 0 failures. Runner exit status 0 (no unexpected failures, warnings or skips) |

Full per-module output: [`conformance/results/2026-11-19-summary.txt`](../conformance/results/2026-11-19-summary.txt).
The discovery document that was tested:
[`conformance/results/2026-11-19-discovery.json`](../conformance/results/2026-11-19-discovery.json).

### Basic OP (35 modules)

| Result | Count | Modules |
| --- | --- | --- |
| Passed | 24 | `oidcc-server`, `oidcc-server-client-secret-post`, `oidcc-response-type-missing`, `oidcc-userinfo-get`, `oidcc-userinfo-post-header`, `oidcc-userinfo-post-body`, `oidcc-ensure-request-without-nonce-succeeds-for-code-flow`, `oidcc-scope-email`, `oidcc-alternate-happy-flow`, `oidcc-display-page`, `oidcc-display-popup`, `oidcc-prompt-none-not-logged-in`, `oidcc-prompt-none-logged-in`, `oidcc-max-age-10000`, `oidcc-ensure-request-with-unknown-parameter-succeeds`, `oidcc-id-token-hint`, `oidcc-login-hint`, `oidcc-ui-locales`, `oidcc-claims-locales`, `oidcc-codereuse`, `oidcc-codereuse-30seconds`, `oidcc-ensure-post-request-succeeds`, `oidcc-refresh-token`, `oidcc-ensure-request-with-valid-pkce-succeeds` |
| Review | 4 | `oidcc-prompt-login`, `oidcc-max-age-1`, `oidcc-ensure-registered-redirect-uri`, `oidcc-ensure-request-object-with-redirect-uri` |
| Warning (expected) | 3 | `oidcc-scope-profile`, `oidcc-ensure-request-with-acr-values-succeeds`, `oidcc-claims-essential` |
| Skipped (expected) | 4 | `oidcc-scope-address`, `oidcc-scope-phone`, `oidcc-scope-all`, `oidcc-unsigned-request-object-supported-correctly-or-rejected-as-unsupported` |

Across all modules: 1,749 successful conditions, 0 failures, 3 warnings.

### Config OP (1 module)

| Result | Module |
| --- | --- |
| Passed | `oidcc-discovery-endpoint-verification` (32 conditions, 0 failures, 0 warnings) |

## What "Review" means

These modules passed every automated check but ask a person to confirm a
screenshot, which the run captures automatically:

- `oidcc-prompt-login` and `oidcc-max-age-1`: the second authorization must
  show the login page again. The screenshot shows the demo login form.
- `oidcc-ensure-registered-redirect-uri` and
  `oidcc-ensure-request-object-with-redirect-uri`: an unregistered
  `redirect_uri` must produce an error page, not a redirect. The screenshot
  shows "This sign-in request cannot be completed".

For a formal certification submission, review the screenshots in the
exported plan archives (`conformance/.runtime/results/*.zip`) and attach
them.

## Expected warnings and skips, and why

These are listed with their reasons in `conformance/expected-warnings.json`
and `conformance/expected-skips.json`, so the runner fails on anything new.

| Module | Result | Reason |
| --- | --- | --- |
| `oidcc-scope-profile` | warning | The demo user has no data for the optional `profile` claims (`middle_name`, `nickname`, `profile`, `picture`, `website`, `gender`, `birthdate`, `zoneinfo`, `locale`). Claims without data are omitted, which OpenID Connect Core 5.4 allows. |
| `oidcc-ensure-request-with-acr-values-succeeds` | warning | `acr` is not supported, so the recommendation to return an `acr` claim does not apply. The request itself succeeds. |
| `oidcc-claims-essential` | warning | The `claims` request parameter is not supported (`claims_parameter_supported: false`). With only the `openid` scope, `name` is correctly not released. |
| `oidcc-scope-address`, `oidcc-scope-phone`, `oidcc-scope-all` | skipped | `address` and `phone` are not configured in the demo app, so they are not in `scopes_supported`. Applications can add them (see [scopes-and-claims.md](scopes-and-claims.md)). |
| `oidcc-unsigned-request-object-supported-correctly-or-rejected-as-unsupported` | skipped | Request objects are out of scope (`request_parameter_supported: false`). |

## Running it yourself

Requirements: Docker with Compose v2, git, curl, at least 6 GB of free
memory, and `composer install` already run in the repository.

```bash
./conformance/run.sh
```

The script:

1. checks free memory and fetches the suite's runner scripts at the pinned
   tag into `conformance/.runtime/suite`;
2. starts MongoDB, the conformance suite, its nginx front end, the demo
   provider (PHP 8.4 built-in server) and a Caddy TLS proxy that serves the
   provider as `https://op.openid.test`;
3. seeds the demo user and two confidential clients
   (`conformance/docker-compose.yml`);
4. runs both plans with the suite's own `run-test-plan.py`. Browser steps
   (login, consent, error page screenshots) are automated through the
   `browser` section of `conformance/config/laravel-openid-provider.json`;
5. writes the output and exported plan archives to
   `conformance/.runtime/results/` and removes every container and volume.

The run takes about two minutes once the images are pulled. To inspect a run
in the suite's web interface, start the stack with
`docker compose -f conformance/docker-compose.yml up -d` and open
<https://localhost:8443>. Run `docker compose -f conformance/docker-compose.yml down -v`
when done.
