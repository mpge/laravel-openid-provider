<?php

namespace Mpge\OpenIdProvider\Contracts;

use Mpge\OpenIdProvider\Concerns\HasOidcSubject;

/**
 * Implemented by the user model to supply the OpenID Connect subject.
 *
 * The subject (`sub`) is the identifier relying parties use to recognise a
 * user. It must never change for a given user and must never be reused for
 * another user. Moodle, for example, links accounts on it.
 *
 * Use the {@see HasOidcSubject} trait for the
 * default (the primary key as a string). Models that do not implement this
 * contract get the same default.
 */
interface OidcSubject
{
    /**
     * Return the stable, unique subject identifier for this user.
     */
    public function getOidcSubject(): string;
}
