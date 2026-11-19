<?php

namespace Mpge\OpenIdProvider\Concerns;

/**
 * Default {@see \Mpge\OpenIdProvider\Contracts\OidcSubject} implementation:
 * the model's primary key as a string.
 *
 * Override getOidcSubject() if the primary key may ever change (for example
 * when moving between databases) and use a dedicated immutable column.
 */
trait HasOidcSubject
{
    /**
     * Return the stable, unique subject identifier for this user.
     */
    public function getOidcSubject(): string
    {
        return (string) $this->getKey();
    }
}
