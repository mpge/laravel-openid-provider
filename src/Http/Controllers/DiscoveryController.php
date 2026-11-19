<?php

namespace Mpge\OpenIdProvider\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Laravel\Passport\Passport;
use Mpge\OpenIdProvider\Claims\ClaimsResolver;
use Mpge\OpenIdProvider\Support\Issuer;

/**
 * The discovery document (OpenID Connect Discovery 1.0 section 4).
 *
 * Lists only what this provider actually supports.
 */
class DiscoveryController
{
    public function __construct(private readonly ClaimsResolver $claims) {}

    /**
     * GET /.well-known/openid-configuration
     */
    public function __invoke(): JsonResponse
    {
        $grantTypes = array_values(array_intersect(
            ['authorization_code', 'refresh_token', 'client_credentials'],
            array_map('strval', (array) config('openid-provider.grant_types', [])),
        ));

        $claims = ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce'];

        foreach ($this->claims->claimNamesByScope() as $names) {
            $claims = array_merge($claims, $names);
        }

        $document = [
            'issuer' => Issuer::identifier(),
            'authorization_endpoint' => $this->endpoint('passport.authorizations.authorize'),
            'token_endpoint' => $this->endpoint('passport.token'),
            'userinfo_endpoint' => $this->endpoint('oidc.userinfo'),
            'jwks_uri' => $this->endpoint('oidc.jwks'),
            'revocation_endpoint' => $this->endpoint('oidc.revoke'),
            'scopes_supported' => array_values(array_unique(array_merge(['openid'], array_keys(Passport::$scopes)))),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => $grantTypes,
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'revocation_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => array_values(array_unique($claims)),
            'prompt_values_supported' => ['none', 'login', 'consent', 'select_account'],
            'claims_parameter_supported' => false,
            'request_parameter_supported' => false,
            'request_uri_parameter_supported' => false,
            'authorization_response_iss_parameter_supported' => false,
        ];

        return new JsonResponse($document, 200, [
            'Cache-Control' => 'public, max-age=3600',
            'Access-Control-Allow-Origin' => '*',
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Endpoint URLs are built from the issuer, not from the incoming
     * request, so they are stable behind proxies and load balancers.
     */
    private function endpoint(string $route): string
    {
        return Issuer::url(route($route, [], false));
    }
}
