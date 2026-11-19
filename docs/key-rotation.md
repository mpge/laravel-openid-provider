# Key rotation runbook

ID tokens are signed with RS256. Clients verify them with the public keys in
`/.well-known/jwks.json`, choosing the key by the `kid` in the token header.
Rotation therefore has to publish a new key before using it, and keep the old
key published until tokens signed with it have expired.

## Key slots

| Slot | File | Published in JWKS | Signs ID tokens |
| --- | --- | --- | --- |
| current | `current.key`, or the Passport private key when the file does not exist | yes | yes |
| next | `next.key` | yes | no |
| previous | `previous.key` | yes | no |

Files live in `keys.directory` (default `storage/openid-provider`), are
written with mode `0600`, and are never committed. At most two keys are
published at once: current plus next (before a rotation) or current plus
previous (after one).

Each key's `kid` is its RFC 7638 thumbprint, unless `keys.current_kid`
overrides the current key's `kid`. When a key with an overridden `kid` is
rotated out, its `kid` is remembered in `previous.key.kid` so tokens it signed
still verify.

Passport's access tokens are always signed with the Passport key. Rotating the
ID token key does not affect them; clients treat access tokens as opaque.

## Check the current state

```bash
php artisan openid-provider:rotate-keys
```

```
+----------+---------------------------------------------+----------------------+
| Slot     | kid                                         | Source               |
+----------+---------------------------------------------+----------------------+
| current  | 5Oixaq8ZaTOHWcqmPPBmdT8WeErgUZ4B3UDHxF-kFvc | Passport private key |
+----------+---------------------------------------------+----------------------+
```

## Planned rotation

1. **Prepare.** Generate the next key and publish it:

   ```bash
   php artisan openid-provider:rotate-keys --prepare
   ```

   JWKS now lists two keys. Nothing else changes. Any previous key still
   published is retired at this point.

2. **Wait** at least `keys.jwks_max_age` seconds (one hour by default), so
   clients that cache the JWKS pick up the new key. If you deploy to several
   servers, make sure `keys.directory` is shared or copied to all of them
   before continuing.

3. **Promote.** Start signing with the new key:

   ```bash
   php artisan openid-provider:rotate-keys --promote
   ```

   The new key becomes current. The old key moves to the previous slot and
   stays published. If you had set `OPENID_PROVIDER_CURRENT_KID`, remove it
   now so the new key is published under its own `kid`.

4. **Wait** at least `id_token_ttl` seconds (one hour by default) so every ID
   token signed by the old key has expired.

5. **Retire.** Stop publishing the old key:

   ```bash
   php artisan openid-provider:rotate-keys --retire
   ```

Back up `keys.directory` after each step. Losing `current.key` makes the
provider fall back to the Passport key, which clients may no longer trust.

## Emergency rotation (key compromise)

If the private key has leaked, waiting protects the attacker, not you:

1. `php artisan openid-provider:rotate-keys --prepare`
2. `php artisan openid-provider:rotate-keys --promote`
3. `php artisan openid-provider:rotate-keys --retire`

Clients that cached the JWKS fail to verify new ID tokens until they refetch
it; most libraries refetch automatically when they see an unknown `kid`. If
the Passport key itself leaked, also run `php artisan passport:keys --force`,
which invalidates every existing access token, and revoke refresh tokens.

## Multiple servers

The key files must be identical on every server. Put `keys.directory` on
shared storage, or run the commands on one server and distribute the files
with your deployment tooling before moving to the next step. Never run
`--prepare` on two servers independently: each would generate a different
key.

## Moving from Passport's key to a dedicated key

Out of the box ID tokens are signed with the Passport private key. To give ID
tokens their own key, simply follow the planned rotation: `--prepare`,
`--promote`, `--retire`. After promotion the Passport key is no longer
published in the JWKS, while Passport keeps using it for access tokens.
