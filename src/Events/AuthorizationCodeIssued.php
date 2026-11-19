<?php

namespace Mpge\OpenIdProvider\Events;

/**
 * Dispatched after an authorization code is issued to a client.
 *
 * Carries identifiers only, never the code itself.
 */
final class AuthorizationCodeIssued
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $userId,
        public readonly array $scopes,
    ) {
    }
}
