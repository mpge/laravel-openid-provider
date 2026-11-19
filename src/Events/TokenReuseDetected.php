<?php

namespace Mpge\OpenIdProvider\Events;

/**
 * Dispatched when an authorization code or refresh token is presented after
 * it was already used. Every token in the grant has been revoked by the time
 * listeners run.
 *
 * Reuse usually means a code or refresh token leaked, so this is a good
 * event to alert on. It carries identifiers only, never token values.
 */
final class TokenReuseDetected
{
    public const AUTHORIZATION_CODE = 'authorization_code';

    public const REFRESH_TOKEN = 'refresh_token';

    public function __construct(
        /** AUTHORIZATION_CODE or REFRESH_TOKEN. */
        public readonly string $type,
        /** The revoked grant. */
        public readonly string $grantId,
        public readonly string $clientId,
        public readonly ?string $userId,
    ) {}
}
