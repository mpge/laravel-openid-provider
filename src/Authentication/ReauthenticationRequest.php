<?php

namespace Mpge\OpenIdProvider\Authentication;

/**
 * Describes why an authorization request needs the user to authenticate
 * again, and where to send them once they have.
 */
final class ReauthenticationRequest
{
    /** The client sent prompt=login. */
    public const REASON_PROMPT_LOGIN = 'prompt_login';

    /** The client's max_age is older than the last authentication. */
    public const REASON_MAX_AGE = 'max_age';

    /** The time of the last authentication is not known for this session. */
    public const REASON_UNKNOWN_AUTH_TIME = 'unknown_auth_time';

    public function __construct(
        /** One of the REASON_* constants. */
        public readonly string $reason,
        /** The client asking for the authorization. */
        public readonly string $clientId,
        /** The client's display name. */
        public readonly string $clientName,
        /** URL that resumes the authorization request. Redirect here when done. */
        public readonly string $resumeUrl,
        /** The max_age the client sent, if any. */
        public readonly ?int $maxAge,
        /** Unix time the user must have authenticated at or after. */
        public readonly int $authenticatedAfter,
    ) {}
}
