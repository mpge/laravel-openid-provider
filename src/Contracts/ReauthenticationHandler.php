<?php

namespace Mpge\OpenIdProvider\Contracts;

use Illuminate\Http\Request;
use Mpge\OpenIdProvider\Authentication\ReauthenticationRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shows the application's own "confirm it is you" step.
 *
 * Called when an authorization request carries `prompt=login`, when its
 * `max_age` is older than the user's last authentication, or when the time
 * of the last authentication is unknown. The handler returns a response
 * (usually a redirect to a password or two-factor screen). Once the user has
 * proved who they are, the application must call
 * {@see \Mpge\OpenIdProvider\Authentication\AuthenticationTime::markAuthenticated()}
 * (or log the user in again through the guard) and redirect to
 * {@see ReauthenticationRequest::$resumeUrl} to finish the authorization.
 *
 * Bind your implementation in a service provider:
 *
 *     $this->app->bind(ReauthenticationHandler::class, ConfirmPasswordForOidc::class);
 */
interface ReauthenticationHandler
{
    /**
     * Return the response that starts re-authentication.
     */
    public function handle(Request $request, ReauthenticationRequest $reauthentication): Response;
}
