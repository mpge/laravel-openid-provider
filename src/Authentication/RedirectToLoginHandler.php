<?php

namespace Mpge\OpenIdProvider\Authentication;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Mpge\OpenIdProvider\Contracts\ReauthenticationHandler;
use Mpge\OpenIdProvider\Support\LoginRedirector;
use Symfony\Component\HttpFoundation\Response;

/**
 * Default re-authentication: sign the user out and send them to the login
 * page. After they sign in again, Laravel's intended redirect resumes the
 * authorization request.
 *
 * Bind your own {@see ReauthenticationHandler} to show a password or
 * two-factor confirmation instead of a full logout.
 */
class RedirectToLoginHandler implements ReauthenticationHandler
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly LoginRedirector $login,
    ) {}

    public function handle(Request $request, ReauthenticationRequest $reauthentication): Response
    {
        $guard = $this->auth->guard((string) config('openid-provider.guard', 'web'));

        if ($guard instanceof StatefulGuard) {
            $guard->logout();
        }

        return $this->login->redirect($request, $reauthentication->resumeUrl);
    }
}
