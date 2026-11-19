<?php

namespace Mpge\OpenIdProvider\Support;

use RuntimeException;

/**
 * The issuer identifier and the URLs derived from it.
 */
final class Issuer
{
    /**
     * The issuer identifier, without a trailing slash.
     *
     * @return non-empty-string
     */
    public static function identifier(): string
    {
        $issuer = config('openid-provider.issuer');

        if (! is_string($issuer) || $issuer === '' || filter_var($issuer, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('openid-provider.issuer must be an absolute URL.');
        }

        if (parse_url($issuer, PHP_URL_QUERY) !== null || parse_url($issuer, PHP_URL_FRAGMENT) !== null) {
            throw new RuntimeException('openid-provider.issuer must not contain a query or fragment.');
        }

        $issuer = rtrim($issuer, '/');

        return $issuer !== '' ? $issuer : throw new RuntimeException('openid-provider.issuer must be an absolute URL.');
    }

    /**
     * An absolute URL for a path under the issuer.
     */
    public static function url(string $path): string
    {
        return self::identifier().'/'.ltrim($path, '/');
    }
}
