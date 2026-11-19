<?php

namespace Mpge\OpenIdProvider\Authorization;

/**
 * An authorization request waiting for the user (login, re-authentication
 * or consent), kept in the session under a random ID.
 */
final class PendingAuthorization
{
    public function __construct(
        /** Random ID used in the resume URL and the consent form. */
        public readonly string $id,
        public readonly AuthorizationParameters $parameters,
        /** Unix time the request arrived. prompt=login needs a login after it. */
        public readonly int $requestedAt,
        /** The user shown the consent screen, once it has been shown. */
        public readonly ?string $consentUserId = null,
    ) {
    }

    /**
     * A copy that records the consent screen was shown to a user.
     */
    public function withConsentShownTo(string $userId): self
    {
        return new self($this->id, $this->parameters, $this->requestedAt, $userId);
    }
}
