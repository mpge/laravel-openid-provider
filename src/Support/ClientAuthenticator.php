<?php

namespace Mpge\OpenIdProvider\Support;

use Laravel\Passport\Bridge\ClientRepository;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Authenticates a client with client_secret_basic or client_secret_post,
 * the same way league/oauth2-server does.
 *
 * Secrets are checked by Passport's client repository, which compares the
 * stored hash with Laravel's Hasher (password_verify, constant time).
 * Public clients authenticate with their client_id alone.
 *
 * @internal
 */
class ClientAuthenticator
{
    public function __construct(private readonly ClientRepository $clients)
    {
    }

    /**
     * The authenticated client, or null when authentication fails.
     */
    public function authenticate(ServerRequestInterface $request, ?string $grantType = null): ?ClientEntityInterface
    {
        [$clientId, $clientSecret, $conflict] = $this->credentials($request);

        if ($conflict || $clientId === null || $clientId === '') {
            return null;
        }

        $client = $this->clients->getClientEntity($clientId);

        if ($client === null) {
            return null;
        }

        if ($client->isConfidential() && ! $this->clients->validateClient($clientId, $clientSecret, $grantType)) {
            return null;
        }

        return $client;
    }

    /**
     * Whether the request mixes HTTP Basic credentials with credentials in
     * the body, which RFC 6749 section 2.3 forbids.
     */
    public function hasConflictingCredentials(ServerRequestInterface $request): bool
    {
        return $this->credentials($request)[2];
    }

    /**
     * Whether the request used HTTP Basic authentication.
     */
    public function usedBasicAuthentication(ServerRequestInterface $request): bool
    {
        return str_starts_with(strtolower($request->getHeaderLine('Authorization')), 'basic ');
    }

    /**
     * The client ID and secret from the Authorization header or the body.
     *
     * @return array{0: string|null, 1: string|null, 2: bool} [id, secret, conflicting credentials]
     */
    private function credentials(ServerRequestInterface $request): array
    {
        $basicId = null;
        $basicSecret = null;
        $header = $request->getHeaderLine('Authorization');

        if (str_starts_with(strtolower($header), 'basic ')) {
            $decoded = base64_decode(substr($header, 6), true);

            if ($decoded === false || ! str_contains($decoded, ':')) {
                return [null, null, true];
            }

            [$basicId, $basicSecret] = explode(':', $decoded, 2);
        }

        $body = (array) $request->getParsedBody();
        $postId = is_string($body['client_id'] ?? null) ? $body['client_id'] : null;
        $postSecret = is_string($body['client_secret'] ?? null) ? $body['client_secret'] : null;

        // Using two authentication methods at once is not allowed (RFC 6749 2.3).
        if ($basicId !== null && ($postSecret !== null || ($postId !== null && $postId !== $basicId))) {
            return [null, null, true];
        }

        return [$basicId ?? $postId, $basicSecret ?? $postSecret, false];
    }
}
