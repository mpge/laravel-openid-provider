# Scopes and claims

## How claims are released

- `sub` is always returned. It comes from `OidcSubject::getOidcSubject()` on
  the user model, or the primary key as a string when the model does not
  implement the contract.
- Every other claim belongs to a scope. UserInfo returns a claim only when the
  access token was granted the scope that releases it.
- A claim whose resolver returns `null` is left out.
- ID tokens carry only protocol claims (`iss`, `sub`, `aud`, `exp`, `iat`,
  `auth_time`, `nonce`) unless `id_token.include_scope_claims` is `true`.
- A resolver that throws makes the request fail. The provider never answers
  with partial data.

The default configuration ships two scopes:

| Scope | Claims | Default resolvers |
| --- | --- | --- |
| `profile` | `name`, `given_name`, `family_name`, `preferred_username`, `updated_at` | attributes `name`, `given_name`, `family_name`, `username`; `UpdatedAt` (Unix time) |
| `email` | `email`, `email_verified` | attribute `email`; `EmailVerified` (uses `MustVerifyEmail` or `email_verified_at`) |

Attributes that do not exist on your model resolve to `null` and are omitted,
so adjust the map to your schema. A typical `users` table has only `name`;
if clients need `given_name` and `family_name` (Moodle does), add those
columns or point the claims at a resolver.

`address` and `phone` are not configured by default. Add them as below if you
store that data.

## Resolvers

```php
'claims' => [
    // An attribute, read with data_get(), so nested paths work.
    'locale' => 'preferences.locale',

    // A class implementing ClaimResolver, resolved from the container.
    'groups' => App\OpenId\GroupsResolver::class,

    // A [class, method] pair, called as $instance->method($user, $claim).
    'picture' => [App\OpenId\Avatars::class, 'url'],
],
```

Closures are not supported in the configuration file because they break
`php artisan config:cache`.

## Example: a custom scope

An application wants clients to see a user's Acme access level and the teams
they belong to, under a new `acme_access` scope.

1. Add the scope to `config/openid-provider.php`:

   ```php
   'scopes' => [
       'profile' => [/* ... */],
       'email' => [/* ... */],

       'acme_access' => [
           'description' => 'Your Acme access level and teams.',
           'claims' => [
               'acme_level' => 'access_level',
               'acme_teams' => App\OpenId\AcmeTeams::class,
           ],
       ],
   ],
   ```

2. Write the resolver:

   ```php
   namespace App\OpenId;

   use Illuminate\Contracts\Auth\Authenticatable;
   use Mpge\OpenIdProvider\Contracts\ClaimResolver;

   class AcmeTeams implements ClaimResolver
   {
       public function resolve(Authenticatable $user, string $claim): ?array
       {
           $teams = $user->teams()->orderBy('name')->pluck('slug')->all();

           return $teams === [] ? null : $teams;
       }
   }
   ```

3. Clients request it like any other scope:

   ```
   scope=openid email acme_access
   ```

   and UserInfo answers:

   ```json
   {
       "sub": "42",
       "email": "ada@example.com",
       "email_verified": true,
       "acme_level": "editor",
       "acme_teams": ["design", "research"]
   }
   ```

The package registers `acme_access` with Passport (with the description shown
on the consent screen) and lists it in discovery under `scopes_supported`, and
its claims under `claims_supported`.

To restrict which clients may request the scope, use Passport's per-client
scopes: give the client a `scopes` column value, or override
`Client::hasScope()`. Scopes a client is not allowed are silently dropped from
the grant.

## Unknown scopes

For OpenID Connect requests (those that include `openid`), scopes the
provider does not know are ignored, as OpenID Connect Core 3.1.2.1 requires.
They are never granted. Plain OAuth 2.0 requests (without `openid`) keep the
strict behaviour and are refused with `invalid_scope`.

## Address and phone

```php
'address' => [
    'description' => 'Your postal address.',
    'claims' => [
        'address' => App\OpenId\PostalAddress::class, // return ['formatted' => ..., 'country' => ...]
    ],
],
'phone' => [
    'description' => 'Your phone number.',
    'claims' => [
        'phone_number' => 'phone',
        'phone_number_verified' => App\OpenId\PhoneVerified::class,
    ],
],
```
