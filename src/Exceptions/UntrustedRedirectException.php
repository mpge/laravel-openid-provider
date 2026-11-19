<?php

namespace Mpge\OpenIdProvider\Exceptions;

use RuntimeException;

/**
 * An authorization request error that must be shown to the user and never
 * redirected, because the client or its redirect_uri cannot be trusted.
 */
class UntrustedRedirectException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        public readonly string $description,
    ) {
        parent::__construct($description);
    }
}
