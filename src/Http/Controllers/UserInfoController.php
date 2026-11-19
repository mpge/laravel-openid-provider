<?php

namespace Mpge\OpenIdProvider\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mpge\OpenIdProvider\Claims\ClaimsResolver;
use Mpge\OpenIdProvider\Support\AccessTokenValidator;
use Mpge\OpenIdProvider\Support\UserResolver;

/**
 * The UserInfo endpoint (OpenID Connect Core 5.3).
 *
 * Accepts the access token in the Authorization header (GET or POST) or as
 * an `access_token` form field on POST (RFC 6750 2.2). Returns `sub` plus
 * only the claims of the scopes granted to the token.
 */
class UserInfoController
{
    public function __construct(
        private readonly AccessTokenValidator $tokens,
        private readonly UserResolver $users,
        private readonly ClaimsResolver $claims,
    ) {}

    /**
     * GET|POST /oauth/userinfo
     */
    public function __invoke(Request $request): JsonResponse
    {
        $token = $this->bearerToken($request);

        if ($token === false) {
            return $this->unauthorized('invalid_request', 'Send the access token once, in the Authorization header or the form body. Query strings are not accepted.', 400);
        }

        if ($token === null) {
            return $this->unauthorized(null, null, 401);
        }

        $attributes = $this->tokens->validate($token);

        if ($attributes === null || $attributes['user_id'] === null) {
            return $this->unauthorized('invalid_token', 'The access token is invalid, expired or revoked.', 401);
        }

        if (! in_array('openid', $attributes['scopes'], true)) {
            return $this->unauthorized('insufficient_scope', 'The access token was not granted the openid scope.', 403);
        }

        $user = $this->users->find($attributes['user_id']);

        if ($user === null) {
            return $this->unauthorized('invalid_token', 'The access token is invalid, expired or revoked.', 401);
        }

        return new JsonResponse(
            $this->claims->claimsFor($user, $attributes['scopes']),
            200,
            ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * The access token, null when none was sent, false when sent twice.
     */
    private function bearerToken(Request $request): string|false|null
    {
        $header = $request->header('Authorization');
        $fromHeader = null;

        if (is_string($header) && preg_match('/^Bearer\s+(\S+)\s*$/i', $header, $matches) === 1) {
            $fromHeader = $matches[1];
        }

        $fromBody = null;

        if ($request->isMethod('POST') && str_starts_with((string) $request->header('Content-Type'), 'application/x-www-form-urlencoded')) {
            $value = $request->request->get('access_token');
            $fromBody = is_string($value) && $value !== '' ? $value : null;
        }

        if ($fromHeader !== null && $fromBody !== null) {
            return false;
        }

        if ($request->query->has('access_token')) {
            return false;
        }

        return $fromHeader ?? $fromBody;
    }

    private function unauthorized(?string $error, ?string $description, int $status): JsonResponse
    {
        $challenge = 'Bearer realm="'.addslashes((string) config('openid-provider.issuer')).'"';

        if ($error !== null) {
            $challenge .= ', error="'.$error.'", error_description="'.$description.'"';
        }

        return new JsonResponse(
            $error === null ? [] : ['error' => $error, 'error_description' => $description],
            $status,
            ['WWW-Authenticate' => $challenge, 'Cache-Control' => 'no-store'],
        );
    }
}
