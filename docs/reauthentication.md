# Re-authentication

Clients can ask the provider to confirm that the user really is present:

- `prompt=login`: the user must authenticate again, even with a valid session.
- `max_age=N`: the user must have authenticated within the last `N` seconds.
- `prompt=none`: never show any UI. If the user is not signed in, is not
  recent enough for `max_age`, or has not consented, the client receives
  `login_required` or `consent_required` instead.

## How authentication time is tracked

The package listens for Laravel's `Login` event on the configured guard and
stores the time in the session, together with the user it belongs to. That
time becomes the `auth_time` claim of the ID token. It is cleared on logout.

If the time is unknown (for example a session that existed before the package
was installed), the provider treats it as too old and asks the user to
re-authenticate. It never guesses.

## The default behaviour

Without any setup, re-authentication signs the user out and redirects them to
the login page. When they sign in again, Laravel's intended redirect resumes
the authorization request. This works with every login implementation that
ends in `redirect()->intended()`.

## Using your own screen

Most applications prefer to ask only for the password (or a two-factor code)
instead of a full sign-out. Implement the contract:

```php
namespace Mpge\OpenIdProvider\Contracts;

interface ReauthenticationHandler
{
    public function handle(Request $request, ReauthenticationRequest $reauthentication): Response;
}
```

`ReauthenticationRequest` tells you why and where to go next:

| Property | Meaning |
| --- | --- |
| `reason` | `prompt_login`, `max_age` or `unknown_auth_time` |
| `clientId`, `clientName` | The client asking |
| `resumeUrl` | Redirect here once the user has proved who they are |
| `maxAge` | The `max_age` the client sent, if any |
| `authenticatedAfter` | Unix time the authentication must be at or after |

### Example: password confirmation

The handler stores the resume URL and shows the application's own screen:

```php
namespace App\OpenId;

use Illuminate\Http\Request;
use Mpge\OpenIdProvider\Authentication\ReauthenticationRequest;
use Mpge\OpenIdProvider\Contracts\ReauthenticationHandler;
use Symfony\Component\HttpFoundation\Response;

class ConfirmPassword implements ReauthenticationHandler
{
    public function handle(Request $request, ReauthenticationRequest $reauthentication): Response
    {
        $request->session()->put('openid.resume', $reauthentication->resumeUrl);

        return redirect()->route('openid.confirm-password');
    }
}
```

Bind it in a service provider:

```php
use App\OpenId\ConfirmPassword;
use Mpge\OpenIdProvider\Contracts\ReauthenticationHandler;

public function register(): void
{
    $this->app->bind(ReauthenticationHandler::class, ConfirmPassword::class);
}
```

The controller behind the screen verifies the password, records the new
authentication time and resumes:

```php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Mpge\OpenIdProvider\Authentication\AuthenticationTime;

class ConfirmPasswordForOpenIdController
{
    public function show()
    {
        return view('auth.confirm-password');
    }

    public function store(Request $request, AuthenticationTime $authTime)
    {
        $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($request->input('password'), $request->user()->getAuthPassword())) {
            throw ValidationException::withMessages(['password' => __('auth.password')]);
        }

        $authTime->markAuthenticated($request->session(), $request->user());

        $resume = $request->session()->pull('openid.resume');

        // Only ever follow a resume URL the provider generated.
        abort_unless(is_string($resume) && str_starts_with($resume, route('oidc.authorizations.continue')), 400);

        return redirect()->to($resume);
    }
}
```

```php
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/openid/confirm-password', [ConfirmPasswordForOpenIdController::class, 'show'])
        ->name('openid.confirm-password');
    Route::post('/openid/confirm-password', [ConfirmPasswordForOpenIdController::class, 'store'])
        ->middleware('throttle:6,1');
});
```

A two-factor challenge works the same way: verify the code, call
`markAuthenticated()`, redirect to the resume URL.

Signing the user in again through the guard (`Auth::login()`,
`Auth::attempt()`) also records a new time, so handlers built on existing
login flows do not need to call `markAuthenticated()` themselves.

## Resuming

The resume URL is `/oauth/authorize/continue?request=ID`. The ID refers to the
pending request stored in the user's session; it expires after
`pending_authorization_ttl` seconds (30 minutes by default). On resume the
provider checks everything again: the client must still be active, the user
must be signed in, and the authentication time must now satisfy
`prompt=login` (an authentication after the request arrived) and `max_age`.
If it still does not, the handler is called again.
