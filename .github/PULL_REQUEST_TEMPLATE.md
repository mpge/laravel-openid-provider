## What and why

<!-- What does this change, and which problem or issue does it address? -->

## How it was tested

<!-- Feature tests added or changed, conformance plans or Moodle smoke test run, manual checks. -->

## Checklist

- [ ] Tests cover the change (security fixes include a test that fails without the fix)
- [ ] `composer test`, `composer analyse` and `composer lint` pass
- [ ] Documentation in `docs/` and `CHANGELOG.md` (Unreleased) updated
- [ ] No hand-written cryptography, no secrets in logs, exceptions or events
- [ ] No dev-stability dependencies
