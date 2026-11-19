<?php

namespace Mpge\OpenIdProvider\Authentication;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;

/**
 * Tracks when the signed-in user last authenticated, per session.
 *
 * Every login on the configured guard records the time automatically. Call
 * markAuthenticated() after your own re-authentication step (password
 * confirmation, two-factor challenge) to refresh it. The value is bound to
 * the user it was recorded for, so it never carries over to another user
 * signing in on the same session.
 */
class AuthenticationTime
{
    public const SESSION_KEY = 'openid_provider.auth_time';

    /**
     * Record that the user authenticated just now.
     */
    public function markAuthenticated(Session $session, Authenticatable $user, ?int $timestamp = null): void
    {
        $session->put(self::SESSION_KEY, [
            'user' => (string) $user->getAuthIdentifier(),
            'time' => $timestamp ?? time(),
        ]);
    }

    /**
     * When the user last authenticated in this session, or null if unknown.
     */
    public function for(Session $session, Authenticatable $user): ?int
    {
        $record = $session->get(self::SESSION_KEY);

        if (! is_array($record) || ! isset($record['user'], $record['time'])) {
            return null;
        }

        if (! hash_equals((string) $record['user'], (string) $user->getAuthIdentifier())) {
            return null;
        }

        return is_int($record['time']) ? $record['time'] : null;
    }

    /**
     * Forget the recorded time (for example on logout).
     */
    public function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }
}
