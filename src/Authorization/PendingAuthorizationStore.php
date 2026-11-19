<?php

namespace Mpge\OpenIdProvider\Authorization;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * Keeps unfinished authorization requests in the session.
 *
 * Entries expire after `pending_authorization_ttl` seconds and at most
 * MAX_ENTRIES are kept per session.
 */
class PendingAuthorizationStore
{
    public const SESSION_KEY = 'openid_provider.pending';

    public const MAX_ENTRIES = 10;

    /**
     * Store a new pending authorization.
     */
    public function create(Session $session, AuthorizationParameters $parameters): PendingAuthorization
    {
        $pending = new PendingAuthorization(Str::random(40), $parameters, time());

        $this->put($session, $pending);

        return $pending;
    }

    /**
     * Save (or replace) a pending authorization.
     */
    public function put(Session $session, PendingAuthorization $pending): void
    {
        $entries = $this->entries($session);

        $entries[$pending->id] = [
            'parameters' => $pending->parameters->toArray(),
            'requested_at' => $pending->requestedAt,
            'consent_user_id' => $pending->consentUserId,
        ];

        uasort($entries, fn (array $a, array $b): int => $a['requested_at'] <=> $b['requested_at']);

        $session->put(self::SESSION_KEY, array_slice($entries, -self::MAX_ENTRIES, null, true));
    }

    /**
     * Find a pending authorization that has not expired.
     */
    public function find(Session $session, ?string $id): ?PendingAuthorization
    {
        if ($id === null || $id === '') {
            return null;
        }

        $entry = $this->entries($session)[$id] ?? null;

        if ($entry === null) {
            return null;
        }

        return new PendingAuthorization(
            $id,
            AuthorizationParameters::fromArray($entry['parameters']),
            $entry['requested_at'],
            $entry['consent_user_id'],
        );
    }

    /**
     * Remove a pending authorization.
     */
    public function forget(Session $session, string $id): void
    {
        $entries = $this->entries($session);
        unset($entries[$id]);

        $session->put(self::SESSION_KEY, $entries);
    }

    /**
     * Unexpired entries.
     *
     * @return array<string, array{parameters: array<string, mixed>, requested_at: int, consent_user_id: string|null}>
     */
    private function entries(Session $session): array
    {
        $entries = $session->get(self::SESSION_KEY, []);

        if (! is_array($entries)) {
            return [];
        }

        $oldest = time() - (int) config('openid-provider.pending_authorization_ttl', 1800);
        $valid = [];

        foreach ($entries as $id => $entry) {
            if (is_array($entry)
                && is_array($entry['parameters'] ?? null)
                && is_int($entry['requested_at'] ?? null)
                && $entry['requested_at'] >= $oldest) {
                $valid[(string) $id] = [
                    'parameters' => $entry['parameters'],
                    'requested_at' => $entry['requested_at'],
                    'consent_user_id' => is_string($entry['consent_user_id'] ?? null) ? $entry['consent_user_id'] : null,
                ];
            }
        }

        return $valid;
    }
}
