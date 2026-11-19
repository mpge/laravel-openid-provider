# Upgrading

This page lists what to do when moving between versions of this package.
Moving from another package? See
[migrating-from-admin9.md](migrating-from-admin9.md).

## Versioning

The package follows [Semantic Versioning](https://semver.org/). Before 1.0,
a minor release (0.1 to 0.2) may contain breaking changes; they are always
listed here and in [CHANGELOG.md](../CHANGELOG.md). Patch releases never
break anything.

Things that are part of the public API:

- the contracts in `Mpge\OpenIdProvider\Contracts`;
- `Mpge\OpenIdProvider\Concerns\HasOidcSubject`;
- `Mpge\OpenIdProvider\Authentication\AuthenticationTime` and
  `ReauthenticationRequest`;
- the claim resolvers in `Mpge\OpenIdProvider\Claims\Resolvers`;
- the events in `Mpge\OpenIdProvider\Events`;
- configuration keys, route paths and route names;
- the `openid-provider:rotate-keys` command.

Classes marked `@internal` can change in any release.

## General steps for every upgrade

1. Read the section for the target version below and the changelog.
2. `composer update mpge/laravel-openid-provider`
3. `php artisan migrate` (new migrations are listed in the changelog).
4. Compare your published `config/openid-provider.php` with the package's
   copy for new keys. Missing keys fall back to the package defaults.
5. If you published the views, compare them with the new versions.
6. Check discovery and sign in through one client on staging.

## 0.1.0

First release. Nothing to upgrade.
