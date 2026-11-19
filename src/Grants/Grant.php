<?php

namespace Mpge\OpenIdProvider\Grants;

/**
 * One authorization and the facts the ID token needs from it.
 *
 * The grant ID is the Passport authorization code ID for grants that began
 * at the authorization endpoint.
 */
final class Grant
{
    public function __construct(
        public readonly string $id,
        public readonly string $clientId,
        public readonly ?string $userId,
        public readonly ?string $nonce,
        public readonly ?int $authTime,
        public readonly bool $revoked,
    ) {}
}
