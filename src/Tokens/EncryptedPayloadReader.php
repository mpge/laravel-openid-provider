<?php

namespace Mpge\OpenIdProvider\Tokens;

use Illuminate\Contracts\Encryption\Encrypter;
use Laravel\Passport\Passport;
use League\OAuth2\Server\CryptTrait;
use SensitiveParameter;
use Throwable;

/**
 * Reads the encrypted payloads league/oauth2-server puts in authorization
 * codes and refresh tokens.
 *
 * Decryption goes through league/oauth2-server's own CryptTrait with the
 * same key Passport configures, so no cryptography is implemented here.
 * Any failure returns null; callers then let league/oauth2-server reject
 * the request with its normal error.
 */
class EncryptedPayloadReader
{
    use CryptTrait;

    public function __construct(Encrypter $encrypter)
    {
        $this->setEncryptionKey(Passport::tokenEncryptionKey($encrypter));
    }

    /**
     * The payload of an authorization code, or null if it cannot be read.
     *
     * @return array{auth_code_id: string, client_id: string, user_id: string|null, redirect_uri: string|null, expire_time: int}|null
     */
    public function authorizationCode(#[SensitiveParameter] string $code): ?array
    {
        $payload = $this->decode($code);

        if ($payload === null || ! is_string($payload['auth_code_id'] ?? null) || ! is_string($payload['client_id'] ?? null)) {
            return null;
        }

        return [
            'auth_code_id' => $payload['auth_code_id'],
            'client_id' => $payload['client_id'],
            'user_id' => isset($payload['user_id']) && is_scalar($payload['user_id']) ? (string) $payload['user_id'] : null,
            'redirect_uri' => is_string($payload['redirect_uri'] ?? null) ? $payload['redirect_uri'] : null,
            'expire_time' => is_numeric($payload['expire_time'] ?? null) ? (int) $payload['expire_time'] : 0,
        ];
    }

    /**
     * The payload of a refresh token, or null if it cannot be read.
     *
     * @return array{refresh_token_id: string, access_token_id: string, client_id: string, user_id: string|null}|null
     */
    public function refreshToken(#[SensitiveParameter] string $token): ?array
    {
        $payload = $this->decode($token);

        if ($payload === null
            || ! is_string($payload['refresh_token_id'] ?? null)
            || ! is_string($payload['access_token_id'] ?? null)
            || ! is_string($payload['client_id'] ?? null)) {
            return null;
        }

        return [
            'refresh_token_id' => $payload['refresh_token_id'],
            'access_token_id' => $payload['access_token_id'],
            'client_id' => $payload['client_id'],
            'user_id' => isset($payload['user_id']) && is_scalar($payload['user_id']) ? (string) $payload['user_id'] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(#[SensitiveParameter] string $value): ?array
    {
        if ($value === '' || strlen($value) > 8192) {
            return null;
        }

        try {
            $decoded = json_decode($this->decrypt($value), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
