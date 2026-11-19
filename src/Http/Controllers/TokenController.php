<?php

namespace Mpge\OpenIdProvider\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Mpge\OpenIdProvider\Events\TokenReuseDetected;
use Mpge\OpenIdProvider\Grants\GrantRepository;
use Mpge\OpenIdProvider\Support\ClientAuthenticator;
use Mpge\OpenIdProvider\Tokens\EncryptedPayloadReader;
use Mpge\OpenIdProvider\Tokens\IssuanceContext;
use Nyholm\Psr7\Response as PsrResponse;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The token endpoint (RFC 6749 section 3.2, OpenID Connect Core 3.1.3).
 *
 * Before league/oauth2-server handles the request, the presented
 * authorization code or refresh token is claimed with one atomic database
 * update. If the claim fails because it was already used, every token
 * issued from the same grant is revoked (RFC 6749 section 4.1.2 and the
 * refresh token rotation guidance in RFC 9700 section 4.14).
 */
class TokenController
{
    use ConvertsPsrResponses;

    public function __construct(
        private readonly AuthorizationServer $server,
        private readonly GrantRepository $grants,
        private readonly EncryptedPayloadReader $payloads,
        private readonly IssuanceContext $context,
        private readonly ClientAuthenticator $clients,
    ) {
    }

    /**
     * POST /oauth/token
     */
    public function issueToken(ServerRequestInterface $request): Response|JsonResponse
    {
        $body = (array) $request->getParsedBody();
        $grantType = is_string($body['grant_type'] ?? null) ? $body['grant_type'] : null;
        $allowed = array_map('strval', (array) config('openid-provider.grant_types', []));

        $this->context->reset();

        if ($grantType === null) {
            return $this->error('invalid_request', 'The grant_type parameter is missing.', 400);
        }

        if (! in_array($grantType, $allowed, true)) {
            return $this->error('unsupported_grant_type', 'The authorization grant type is not supported.', 400);
        }

        if ($this->clients->hasConflictingCredentials($request)) {
            return $this->error('invalid_request', 'Use exactly one client authentication method.', 400);
        }

        $this->context->grantType = $grantType;

        // Only an authenticated client can redeem (and so burn) its own codes
        // and refresh tokens. Anything else is left for league/oauth2-server
        // to reject with its usual error.
        $client = $this->clients->authenticate($request, $grantType);

        if ($client !== null && $grantType === 'authorization_code' && is_string($body['code'] ?? null)) {
            $this->claimAuthorizationCode($body['code'], $client->getIdentifier());
        }

        if ($client !== null && $grantType === 'refresh_token' && is_string($body['refresh_token'] ?? null)) {
            $this->claimRefreshToken($body['refresh_token'], $client->getIdentifier());
        }

        try {
            return $this->convertResponse(
                $this->server->respondToAccessTokenRequest($request, new PsrResponse)
            );
        } catch (OAuthServerException $e) {
            return $this->convertResponse($e->generateHttpResponse(new PsrResponse));
        } finally {
            $this->context->reset();
        }
    }

    private function claimAuthorizationCode(string $code, string $clientId): void
    {
        $payload = $this->payloads->authorizationCode($code);

        if ($payload === null || ! hash_equals($payload['client_id'], $clientId)) {
            return;
        }

        $codeId = $payload['auth_code_id'];

        if ($this->grants->claimAuthorizationCode($codeId)) {
            $this->context->claimedAuthCodeId = $codeId;
            $this->context->grant = $this->grants->find($codeId);

            return;
        }

        $grant = $this->grants->find($codeId);

        if ($grant !== null) {
            $this->grants->revoke($grant->id);

            event(new TokenReuseDetected(TokenReuseDetected::AUTHORIZATION_CODE, $grant->id, $grant->clientId, $grant->userId));
        }
    }

    private function claimRefreshToken(string $token, string $clientId): void
    {
        $payload = $this->payloads->refreshToken($token);

        if ($payload === null || ! hash_equals($payload['client_id'], $clientId)) {
            return;
        }

        if ($this->grants->claimRefreshToken($payload['refresh_token_id'])) {
            $this->context->claimedRefreshTokenId = $payload['refresh_token_id'];
            $this->context->grant = $this->grants->findOrAdoptAccessToken(
                $payload['access_token_id'],
                $payload['client_id'],
                $payload['user_id'],
            );

            return;
        }

        $grant = $this->grants->findByAccessToken($payload['access_token_id']);

        if ($grant !== null) {
            $this->grants->revoke($grant->id);

            event(new TokenReuseDetected(TokenReuseDetected::REFRESH_TOKEN, $grant->id, $grant->clientId, $grant->userId));
        }
    }

    private function error(string $error, string $description, int $status): JsonResponse
    {
        return new JsonResponse(
            ['error' => $error, 'error_description' => $description],
            $status,
            ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'],
        );
    }
}
