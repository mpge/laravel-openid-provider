<?php

namespace Mpge\OpenIdProvider\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mpge\OpenIdProvider\Keys\KeyStore;
use Mpge\OpenIdProvider\Keys\SigningKey;

/**
 * The JSON Web Key Set (RFC 7517) with the public signing keys.
 *
 * Publishes the current key plus the next key (before a rotation) or the
 * previous key (after one), each with a `kid`.
 */
class JwksController
{
    public function __construct(private readonly KeyStore $keys)
    {
    }

    /**
     * GET /.well-known/jwks.json
     */
    public function __invoke(): JsonResponse
    {
        $maxAge = max(0, (int) config('openid-provider.keys.jwks_max_age', 3600));

        return new JsonResponse(
            ['keys' => array_map(fn (SigningKey $key): array => $key->toJwk(), $this->keys->published())],
            200,
            [
                'Cache-Control' => "public, max-age={$maxAge}",
                'Access-Control-Allow-Origin' => '*',
            ],
            JSON_UNESCAPED_SLASHES,
        );
    }
}
