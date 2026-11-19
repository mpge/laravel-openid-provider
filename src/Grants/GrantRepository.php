<?php

namespace Mpge\OpenIdProvider\Grants;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

/**
 * Stores grants and the tokens that belong to them, and revokes whole
 * token families.
 */
class GrantRepository
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Record the grant created when an authorization code is issued.
     */
    public function createForAuthorizationCode(string $authCodeId, string $clientId, string $userId, ?string $nonce, ?int $authTime): Grant
    {
        $now = Date::now();

        $this->connection()->table('openid_provider_grants')->insert([
            'id' => $authCodeId,
            'client_id' => $clientId,
            'user_id' => $userId,
            'nonce' => $nonce,
            'auth_time' => $authTime,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new Grant($authCodeId, $clientId, $userId, $nonce, $authTime, false);
    }

    /**
     * Find a grant by ID.
     */
    public function find(string $grantId): ?Grant
    {
        $row = $this->connection()->table('openid_provider_grants')->where('id', $grantId)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Find the grant an access token belongs to.
     */
    public function findByAccessToken(string $accessTokenId): ?Grant
    {
        $grantId = $this->connection()->table('openid_provider_grant_tokens')
            ->where('access_token_id', $accessTokenId)
            ->value('grant_id');

        return is_string($grantId) ? $this->find($grantId) : null;
    }

    /**
     * Find the grant an access token belongs to, creating an empty grant for
     * tokens issued before this package was installed.
     */
    public function findOrAdoptAccessToken(string $accessTokenId, string $clientId, ?string $userId): Grant
    {
        $grant = $this->findByAccessToken($accessTokenId);

        if ($grant !== null) {
            return $grant;
        }

        $id = 'adopted-'.Str::uuid()->toString();
        $now = Date::now();

        $this->connection()->table('openid_provider_grants')->insert([
            'id' => $id,
            'client_id' => $clientId,
            'user_id' => $userId,
            'nonce' => null,
            'auth_time' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->attachAccessToken($id, $accessTokenId);

        return new Grant($id, $clientId, $userId, null, null, false);
    }

    /**
     * Link an issued access token to its grant.
     */
    public function attachAccessToken(string $grantId, string $accessTokenId): void
    {
        $this->connection()->table('openid_provider_grant_tokens')->insertOrIgnore([
            'access_token_id' => $accessTokenId,
            'grant_id' => $grantId,
            'created_at' => Date::now(),
        ]);
    }

    /**
     * Atomically mark an authorization code as used. Returns false when the
     * code was already used, revoked or never existed.
     */
    public function claimAuthorizationCode(string $authCodeId): bool
    {
        return Passport::authCode()->newQuery()
            ->whereKey($authCodeId)
            ->where('revoked', false)
            ->update(['revoked' => true]) === 1;
    }

    /**
     * Atomically mark a refresh token as used. Returns false when the token
     * was already used, revoked or never existed.
     */
    public function claimRefreshToken(string $refreshTokenId): bool
    {
        return Passport::refreshToken()->newQuery()
            ->whereKey($refreshTokenId)
            ->where('revoked', false)
            ->update(['revoked' => true]) === 1;
    }

    /**
     * Revoke the grant and every access and refresh token issued from it.
     */
    public function revoke(string $grantId): void
    {
        $accessTokenIds = $this->connection()->table('openid_provider_grant_tokens')
            ->where('grant_id', $grantId)
            ->pluck('access_token_id')
            ->all();

        foreach (array_chunk($accessTokenIds, 500) as $chunk) {
            Passport::token()->newQuery()->whereKey($chunk)->update(['revoked' => true]);
            Passport::refreshToken()->newQuery()->whereIn('access_token_id', $chunk)->update(['revoked' => true]);
        }

        Passport::authCode()->newQuery()->whereKey($grantId)->update(['revoked' => true]);

        $this->connection()->table('openid_provider_grants')
            ->where('id', $grantId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Date::now(), 'updated_at' => Date::now()]);
    }

    private function hydrate(object $row): Grant
    {
        /** @var object{id: string, client_id: string, user_id: string|null, nonce: string|null, auth_time: int|string|null, revoked_at: mixed} $row */
        return new Grant(
            (string) $row->id,
            (string) $row->client_id,
            $row->user_id === null ? null : (string) $row->user_id,
            $row->nonce === null ? null : (string) $row->nonce,
            $row->auth_time === null ? null : (int) $row->auth_time,
            $row->revoked_at !== null,
        );
    }

    private function connection(): ConnectionInterface
    {
        $name = config('passport.connection');

        return $this->db->connection(is_string($name) ? $name : null);
    }
}
