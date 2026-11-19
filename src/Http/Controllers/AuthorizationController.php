<?php

namespace Mpge\OpenIdProvider\Http\Controllers;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Laravel\Passport\Bridge\User as PassportUser;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Mpge\OpenIdProvider\Authentication\AuthenticationTime;
use Mpge\OpenIdProvider\Authentication\ReauthenticationRequest;
use Mpge\OpenIdProvider\Authorization\AuthorizationParameters;
use Mpge\OpenIdProvider\Authorization\AuthorizationRequestValidator;
use Mpge\OpenIdProvider\Authorization\PendingAuthorization;
use Mpge\OpenIdProvider\Authorization\PendingAuthorizationStore;
use Mpge\OpenIdProvider\Contracts\ReauthenticationHandler;
use Mpge\OpenIdProvider\Events\AuthorizationCodeIssued;
use Mpge\OpenIdProvider\Exceptions\AuthorizationException;
use Mpge\OpenIdProvider\Exceptions\UntrustedRedirectException;
use Mpge\OpenIdProvider\Grants\GrantRepository;
use Mpge\OpenIdProvider\Support\ClientLookup;
use Mpge\OpenIdProvider\Support\LoginRedirector;
use Mpge\OpenIdProvider\Tokens\EncryptedPayloadReader;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as PsrResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The authorization endpoint (OpenID Connect Core 3.1.2).
 *
 * Flow: validate the request, make sure the user is signed in recently
 * enough (prompt, max_age), ask for consent unless it can be skipped, then
 * let league/oauth2-server issue the authorization code and record the
 * nonce and auth_time that belong to it.
 */
class AuthorizationController
{
    public function __construct(
        private readonly AuthorizationRequestValidator $validator,
        private readonly PendingAuthorizationStore $pending,
        private readonly AuthenticationTime $authTime,
        private readonly AuthorizationServer $server,
        private readonly GrantRepository $grants,
        private readonly EncryptedPayloadReader $payloads,
        private readonly ClientLookup $clients,
        private readonly LoginRedirector $login,
        private readonly AuthManager $auth,
        private readonly ViewFactory $views,
    ) {}

    /**
     * GET /oauth/authorize
     */
    public function authorize(Request $request): Response
    {
        return $this->start(
            $request,
            $request->query->all(),
            is_string($queryString = $request->server->get('QUERY_STRING')) ? $queryString : '',
        );
    }

    /**
     * Validate a new authorization request and start processing it.
     *
     * @param  array<string, mixed>  $input
     */
    private function start(Request $request, array $input, string $raw): Response
    {
        try {
            $parameters = $this->validator->validate($input, $raw);
        } catch (UntrustedRedirectException $e) {
            return $this->errorPage($e->error, $e->description);
        } catch (AuthorizationException $e) {
            return new RedirectResponse($e->toRedirectUrl());
        }

        $pending = $this->pending->create($request->session(), $parameters);

        return $this->proceed($request, $pending);
    }

    /**
     * GET /oauth/authorize/continue?request={id}
     *
     * Resumes a request after login or re-authentication.
     */
    public function resume(Request $request): Response
    {
        $pending = $this->pending->find($request->session(), $request->query('request') === null ? null : (string) $request->query('request'));

        if ($pending === null) {
            return $this->errorPage('invalid_request', 'This sign-in request has expired. Please return to the application and try again.');
        }

        return $this->proceed($request, $pending);
    }

    /**
     * POST /oauth/authorize
     *
     * Either the consent form being approved (it carries auth_token), or an
     * authorization request sent with POST, which OpenID Connect Core 3.1.2.1
     * requires providers to accept. The route is excluded from Laravel's CSRF
     * middleware for the second case, so the consent form's CSRF token is
     * verified here instead.
     */
    public function approve(Request $request): Response
    {
        if (! $request->request->has('auth_token')) {
            return $this->start($request, $request->request->all(), (string) $request->getContent());
        }

        $token = $request->input('_token') ?? $request->header('X-CSRF-TOKEN');
        $sessionToken = $request->session()->token();

        if (! is_string($token) || ! is_string($sessionToken) || ! hash_equals($sessionToken, $token)) {
            return $this->errorPage('invalid_request', 'This consent form has expired. Please return to the application and try again.', 419);
        }

        return $this->decide($request, true);
    }

    /**
     * DELETE /oauth/authorize (consent denied)
     */
    public function deny(Request $request): Response
    {
        return $this->decide($request, false);
    }

    private function decide(Request $request, bool $approved): Response
    {
        $token = $request->input('auth_token');
        $pending = $this->pending->find($request->session(), is_string($token) ? $token : null);
        $user = $this->guard()->user();

        if ($pending === null || $user === null || $pending->consentUserId === null
            || ! hash_equals($pending->consentUserId, (string) $user->getAuthIdentifier())) {
            return $this->errorPage('invalid_request', 'This consent request is no longer valid. Please return to the application and try again.');
        }

        $this->pending->forget($request->session(), $pending->id);
        $parameters = $pending->parameters;

        if (! $approved) {
            return $this->redirectError($parameters, 'access_denied', 'The user denied the request.');
        }

        return $this->issueCode($request, $parameters, $user);
    }

    private function proceed(Request $request, PendingAuthorization $pending): Response
    {
        $parameters = $pending->parameters;
        $session = $request->session();

        // The client may have been revoked while the user was signing in.
        $client = $this->clients->active($parameters->clientId);

        if ($client === null) {
            $this->pending->forget($session, $pending->id);

            return $this->errorPage('invalid_client', 'The client is unknown or has been revoked.');
        }

        $user = $this->guard()->user();

        if ($user === null) {
            if ($parameters->prompts('none')) {
                $this->pending->forget($session, $pending->id);

                return $this->redirectError($parameters, 'login_required', 'The user is not signed in.');
            }

            return $this->login->redirect($request, $this->resumeUrl($pending));
        }

        $reason = $this->reauthenticationReason($request, $user, $pending);

        if ($reason !== null) {
            if ($parameters->prompts('none')) {
                $this->pending->forget($session, $pending->id);

                return $this->redirectError($parameters, 'login_required', 'The user must sign in again.');
            }

            return app(ReauthenticationHandler::class)->handle($request, new ReauthenticationRequest(
                reason: $reason,
                clientId: $parameters->clientId,
                clientName: $parameters->clientName,
                resumeUrl: $this->resumeUrl($pending),
                maxAge: $parameters->maxAge,
                authenticatedAfter: $this->authenticatedAfter($pending),
            ));
        }

        if (! $parameters->prompts('consent') && $this->consentCanBeSkipped($client, $user, $parameters)) {
            $this->pending->forget($session, $pending->id);

            return $this->issueCode($request, $parameters, $user);
        }

        if ($parameters->prompts('none')) {
            $this->pending->forget($session, $pending->id);

            return $this->redirectError($parameters, 'consent_required', 'The user has not consented to this client.');
        }

        $this->pending->put($session, $pending->withConsentShownTo((string) $user->getAuthIdentifier()));

        return response($this->views->make($this->view('consent.view', 'openid-provider::consent'), [
            'client' => $client,
            'user' => $user,
            'scopes' => Passport::scopesFor($parameters->scopes),
            'authToken' => $pending->id,
            'request' => $request,
        ])->render(), 200, ['Cache-Control' => 'no-store', 'X-Frame-Options' => 'DENY']);
    }

    /**
     * Why the user must authenticate again, or null if they need not.
     */
    private function reauthenticationReason(Request $request, Authenticatable $user, PendingAuthorization $pending): ?string
    {
        $authTime = $this->authTime->for($request->session(), $user);

        if ($authTime === null) {
            return ReauthenticationRequest::REASON_UNKNOWN_AUTH_TIME;
        }

        $parameters = $pending->parameters;

        if ($parameters->prompts('login') && $authTime < $pending->requestedAt) {
            return ReauthenticationRequest::REASON_PROMPT_LOGIN;
        }

        if ($parameters->maxAge !== null && time() - $authTime > $parameters->maxAge) {
            return ReauthenticationRequest::REASON_MAX_AGE;
        }

        return null;
    }

    private function authenticatedAfter(PendingAuthorization $pending): int
    {
        $after = $pending->parameters->prompts('login') ? $pending->requestedAt : 0;

        if ($pending->parameters->maxAge !== null) {
            $after = max($after, time() - $pending->parameters->maxAge);
        }

        return $after;
    }

    private function consentCanBeSkipped(Client $client, Authenticatable $user, AuthorizationParameters $parameters): bool
    {
        $skipped = array_map('strval', (array) config('openid-provider.consent.skip_for_clients', []));

        if (in_array((string) $client->getKey(), $skipped, true)) {
            return true;
        }

        if ($client->skipsAuthorization($user, Passport::scopesFor($parameters->scopes))) {
            return true;
        }

        if (config('openid-provider.consent.remember_previous_consent', true) !== true) {
            return false;
        }

        $activeTokens = $client->tokens()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('revoked', false)
            ->where('expires_at', '>', Date::now())
            ->get(['scopes']);

        if ($activeTokens->isEmpty()) {
            return false;
        }

        $granted = $activeTokens->pluck('scopes')->flatten()->all();

        return array_diff($parameters->scopes, $granted) === [];
    }

    private function issueCode(Request $request, AuthorizationParameters $parameters, Authenticatable $user): Response
    {
        $factory = new Psr17Factory;
        $psrRequest = $factory->createServerRequest('GET', $request->url())
            ->withQueryParams($parameters->toLeagueQuery());

        try {
            $authRequest = $this->server->validateAuthorizationRequest($psrRequest);
            $userId = (string) $user->getAuthIdentifier();

            if ($userId === '') {
                return $this->redirectError($parameters, 'server_error', 'The signed-in user has no identifier.');
            }

            $authRequest->setUser(new PassportUser($userId));
            $authRequest->setAuthorizationApproved(true);

            $response = $this->server->completeAuthorizationRequest($authRequest, new PsrResponse);
        } catch (OAuthServerException $e) {
            return $this->redirectError($parameters, $e->getErrorType(), $e->getMessage());
        }

        $location = $response->getHeaderLine('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $returned);
        $payload = is_string($returned['code'] ?? null) ? $this->payloads->authorizationCode($returned['code']) : null;

        // Fail closed: if the code cannot be tied to its nonce and auth_time,
        // it is never handed to the client; the client receives an error.
        if ($payload === null) {
            return $this->redirectError($parameters, 'server_error', 'The authorization code could not be recorded.');
        }

        $this->grants->createForAuthorizationCode(
            $payload['auth_code_id'],
            $parameters->clientId,
            (string) $user->getAuthIdentifier(),
            $parameters->nonce,
            $this->authTime->for($request->session(), $user),
        );

        event(new AuthorizationCodeIssued($parameters->clientId, (string) $user->getAuthIdentifier(), $parameters->scopes));

        return new RedirectResponse($location, 302, ['Cache-Control' => 'no-store']);
    }

    private function redirectError(AuthorizationParameters $parameters, string $error, string $description): RedirectResponse
    {
        $exception = new AuthorizationException($error, $description, $parameters->redirectUri, $parameters->state);

        return new RedirectResponse($exception->toRedirectUrl(), 302, ['Cache-Control' => 'no-store']);
    }

    private function errorPage(string $error, string $description, int $status = 400): Response
    {
        return response($this->views->make($this->view('error_view', 'openid-provider::error'), [
            'error' => $error,
            'description' => $description,
        ])->render(), $status, ['Cache-Control' => 'no-store']);
    }

    private function resumeUrl(PendingAuthorization $pending): string
    {
        return route('oidc.authorizations.continue', ['request' => $pending->id]);
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

    private function guard(): Guard
    {
        return $this->auth->guard((string) config('openid-provider.guard', 'web'));
    }
}
