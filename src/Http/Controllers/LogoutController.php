<?php

namespace Mpge\OpenIdProvider\Http\Controllers;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Mpge\OpenIdProvider\Claims\ClaimsResolver;
use Mpge\OpenIdProvider\Support\ClientLookup;
use Mpge\OpenIdProvider\Tokens\IdTokenHintValidator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * RP-initiated logout (OpenID Connect RP-Initiated Logout 1.0). Registered
 * only when openid-provider.logout.enabled is true.
 *
 * The session ends at once only when a verified id_token_hint names the
 * signed-in user. Any other request shows a confirmation page whose form is
 * CSRF protected and carries a single-use challenge, so a third-party page
 * cannot sign users out. A post_logout_redirect_uri is followed only when it
 * exactly matches a URI allowed for the client named by the hint or by
 * client_id; otherwise an error page is shown and nothing is redirected.
 */
class LogoutController
{
    public const SESSION_KEY = 'openid_provider.logout';

    /** Seconds a confirmation page stays valid. */
    private const CHALLENGE_TTL = 600;

    public function __construct(
        private readonly IdTokenHintValidator $hints,
        private readonly ClientLookup $clients,
        private readonly ClaimsResolver $claims,
        private readonly AuthManager $auth,
        private readonly ViewFactory $views,
    ) {}

    /**
     * GET, POST /oauth/logout
     */
    public function logout(Request $request): Response
    {
        // HEAD requests (link previews, prefetchers) never end a session.
        if ($request->isMethod('HEAD')) {
            return response('', 200, ['Cache-Control' => 'no-store']);
        }

        $input = $request->isMethod('POST') ? $request->request->all() : $request->query->all();

        foreach (['id_token_hint', 'client_id', 'post_logout_redirect_uri', 'state'] as $name) {
            if (isset($input[$name]) && ! is_string($input[$name])) {
                return $this->errorPage('invalid_request', "The {$name} parameter is malformed.");
            }
        }

        /** @var array<string, string> $input */
        $hintValue = ($input['id_token_hint'] ?? '') !== '' ? $input['id_token_hint'] : null;
        $clientId = ($input['client_id'] ?? '') !== '' ? $input['client_id'] : null;
        $redirectUri = ($input['post_logout_redirect_uri'] ?? '') !== '' ? $input['post_logout_redirect_uri'] : null;
        $state = ($input['state'] ?? '') !== '' ? $input['state'] : null;

        // An invalid hint is ignored rather than refused: the user can still
        // confirm the logout, it just cannot happen without asking.
        $hint = $hintValue === null ? null : $this->hints->validate($hintValue);

        if ($hint !== null) {
            if ($clientId !== null && ! hash_equals($hint['client_id'], $clientId)) {
                return $this->errorPage('invalid_request', 'The client_id does not match the id_token_hint.');
            }

            $clientId = $hint['client_id'];
        }

        $client = null;

        if ($clientId !== null) {
            $client = $this->clients->active($clientId);

            if ($client === null) {
                return $this->errorPage('invalid_request', 'The client is unknown or has been revoked.');
            }
        }

        $destination = $this->destination();

        if ($redirectUri !== null) {
            if ($client === null) {
                return $this->errorPage('invalid_request', 'A post_logout_redirect_uri needs an id_token_hint or a client_id.');
            }

            if (! in_array($redirectUri, $this->allowedRedirectUris($client), true)) {
                return $this->errorPage('invalid_request', 'The post_logout_redirect_uri is not registered for this client.');
            }

            $destination = $state === null ? $redirectUri : $this->withQuery($redirectUri, ['state' => $state]);
        }

        $guard = $this->guard();
        $user = $guard->user();

        // Nobody is signed in: there is no session to end.
        if ($user === null) {
            return $this->redirect($destination);
        }

        if ($hint !== null && hash_equals($this->claims->subject($user), $hint['sub'])) {
            return $this->endSession($request, $guard, $destination);
        }

        $challenge = Str::random(40);

        $request->session()->put(self::SESSION_KEY, [
            'id' => $challenge,
            'destination' => $destination,
            'expires' => Date::now()->getTimestamp() + self::CHALLENGE_TTL,
        ]);

        return response($this->views->make($this->view('logout.view', 'openid-provider::logout'), [
            'client' => $client,
            'clientName' => $client === null ? null : (string) $client->getAttribute('name'),
            'logoutRequest' => $challenge,
            'cancelUrl' => $this->destination(),
        ])->render(), 200, ['Cache-Control' => 'no-store', 'X-Frame-Options' => 'DENY']);
    }

    /**
     * POST /oauth/logout/confirm
     *
     * The confirmation form. Laravel's CSRF middleware runs on this route.
     */
    public function confirm(Request $request): Response
    {
        $challenge = $request->session()->pull(self::SESSION_KEY);
        $submitted = $request->request->get('logout_request');

        if (! is_array($challenge)
            || ! is_string($challenge['id'] ?? null)
            || ! is_string($challenge['destination'] ?? null)
            || ! is_int($challenge['expires'] ?? null)
            || $challenge['expires'] < Date::now()->getTimestamp()
            || ! is_string($submitted)
            || ! hash_equals($challenge['id'], $submitted)) {
            return $this->errorPage('invalid_request', 'This sign-out request has expired. Please sign out from the application again.');
        }

        return $this->endSession($request, $this->guard(), $challenge['destination']);
    }

    /**
     * Sign the user out of the provider and send the browser on.
     */
    private function endSession(Request $request, StatefulGuard $guard, string $destination): Response
    {
        $guard->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->redirect($destination);
    }

    /**
     * The exact URIs a client may be sent back to after logout.
     *
     * @return list<string>
     */
    private function allowedRedirectUris(Client $client): array
    {
        $map = config('openid-provider.logout.post_logout_redirect_uris', []);
        $configured = is_array($map) ? ($map[(string) $client->getKey()] ?? null) : null;

        $uris = is_array($configured) ? $configured : (array) $client->getAttribute('redirect_uris');

        return array_values(array_filter($uris, fn ($uri): bool => is_string($uri) && $uri !== ''));
    }

    /**
     * Where to go when no post_logout_redirect_uri was given.
     */
    private function destination(): string
    {
        $target = config('openid-provider.logout.redirect_to', '/');
        $target = is_string($target) && $target !== '' ? $target : '/';

        return Route::has($target) ? route($target) : url($target);
    }

    /**
     * Add query parameters to a URI that may already have a query.
     *
     * @param  array<string, string>  $parameters
     */
    private function withQuery(string $uri, array $parameters): string
    {
        $fragment = '';

        if (($position = strpos($uri, '#')) !== false) {
            $fragment = substr($uri, $position);
            $uri = substr($uri, 0, $position);
        }

        $separator = str_contains($uri, '?') ? '&' : '?';

        return $uri.$separator.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986).$fragment;
    }

    private function redirect(string $destination): RedirectResponse
    {
        return new RedirectResponse($destination, 302, ['Cache-Control' => 'no-store']);
    }

    private function errorPage(string $error, string $description, int $status = 400): Response
    {
        return response($this->views->make($this->view('error_view', 'openid-provider::error'), [
            'error' => $error,
            'description' => $description,
            'title' => __('This sign-out request cannot be completed'),
        ])->render(), $status, ['Cache-Control' => 'no-store']);
    }

    /**
     * A configured view name.
     *
     * @return view-string
     */
    private function view(string $key, string $default): string
    {
        $view = config("openid-provider.{$key}");

        /** @var view-string $name Validated by the view factory when rendered. */
        $name = is_string($view) && $view !== '' ? $view : $default;

        return $name;
    }

    private function guard(): StatefulGuard
    {
        $guard = $this->auth->guard((string) config('openid-provider.guard', 'web'));

        if (! $guard instanceof StatefulGuard) {
            throw new RuntimeException('openid-provider.guard must be a session (stateful) guard for logout.');
        }

        return $guard;
    }
}
