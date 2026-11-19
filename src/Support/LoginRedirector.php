<?php

namespace Mpge\OpenIdProvider\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

/**
 * Sends the user to the application's login page and remembers where to
 * come back to.
 */
class LoginRedirector
{
    public function __construct(private readonly Router $router) {}

    /**
     * Redirect to the login page, storing $resumeUrl as the intended URL.
     */
    public function redirect(Request $request, string $resumeUrl): RedirectResponse
    {
        $request->session()->put('url.intended', $resumeUrl);

        return new RedirectResponse($this->loginUrl());
    }

    /**
     * The configured login URL (a route name or a path).
     */
    public function loginUrl(): string
    {
        $login = (string) config('openid-provider.login_route', 'login');

        if ($this->router->has($login)) {
            return route($login);
        }

        return url($login);
    }
}
