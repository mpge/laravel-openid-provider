<?php

namespace Mpge\OpenIdProvider\Exceptions;

use RuntimeException;

/**
 * An authorization request error that is returned to the client by
 * redirecting to its (already verified) redirect_uri.
 */
class AuthorizationException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        public readonly string $description,
        public readonly string $redirectUri,
        public readonly ?string $state,
    ) {
        parent::__construct($description);
    }

    /**
     * The redirect URL carrying the error, error_description and state.
     */
    public function toRedirectUrl(): string
    {
        $parameters = ['error' => $this->error, 'error_description' => $this->description];

        if ($this->state !== null) {
            $parameters['state'] = $this->state;
        }

        $separator = str_contains($this->redirectUri, '?') ? '&' : '?';

        return $this->redirectUri.$separator.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
