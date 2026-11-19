<?php

namespace Mpge\OpenIdProvider\Keys;

use Illuminate\Filesystem\Filesystem;
use Laravel\Passport\Passport;
use RuntimeException;

/**
 * Loads and stores the ID token signing keys.
 *
 * At most two keys are published at once:
 *  - current: signs every new ID token;
 *  - next: published ahead of a rotation so clients can cache it;
 *  - previous: published after a rotation until the tokens it signed expire.
 *
 * Keys live in the configured directory as current.key, next.key and
 * previous.key. When current.key does not exist the Passport private key is
 * used, which keeps the signing key unchanged when moving from another
 * Passport based OpenID package.
 */
class KeyStore
{
    public const CURRENT = 'current';

    public const NEXT = 'next';

    public const PREVIOUS = 'previous';

    /** @var array<string, SigningKey|null> */
    private array $loaded = [];

    public function __construct(private readonly Filesystem $files)
    {
    }

    /**
     * The key that signs new ID tokens. Throws when it cannot be read.
     */
    public function current(): SigningKey
    {
        if (! isset($this->loaded[self::CURRENT])) {
            $kid = config('openid-provider.keys.current_kid');

            $this->loaded[self::CURRENT] = new SigningKey(
                $this->read(self::CURRENT) ?? $this->passportPrivateKey(),
                is_string($kid) ? $kid : null,
            );
        }

        return $this->loaded[self::CURRENT];
    }

    /**
     * The key waiting to become current, if one has been prepared.
     */
    public function next(): ?SigningKey
    {
        return $this->optional(self::NEXT);
    }

    /**
     * The key that was current before the last rotation, if still published.
     */
    public function previous(): ?SigningKey
    {
        return $this->optional(self::PREVIOUS);
    }

    /**
     * Every key that should appear in the JWKS document, current first.
     *
     * @return list<SigningKey>
     */
    public function published(): array
    {
        return array_values(array_filter([$this->current(), $this->next(), $this->previous()]));
    }

    /**
     * Whether current.key exists in the key directory (instead of the
     * Passport key being used).
     */
    public function hasStoredCurrentKey(): bool
    {
        return $this->files->exists($this->path(self::CURRENT));
    }

    /**
     * Validate and write a key into a slot, readable only by its owner.
     */
    public function write(string $slot, string $privateKeyPem): void
    {
        new SigningKey($privateKeyPem);

        $this->files->ensureDirectoryExists($this->directory(), 0700);
        $this->files->put($this->path($slot), $privateKeyPem);
        @chmod($this->path($slot), 0600);

        $this->forgetLoaded();
    }

    /**
     * Remove the key in a slot.
     */
    public function delete(string $slot): void
    {
        $this->files->delete($this->path($slot));

        $this->forgetLoaded();
    }

    /**
     * The PEM text stored in a slot, or null when the slot is empty.
     */
    public function read(string $slot): ?string
    {
        $path = $this->path($slot);

        return $this->files->exists($path) ? $this->files->get($path) : null;
    }

    /**
     * The PEM text of the Passport private key.
     */
    public function passportPrivateKey(): string
    {
        $configured = config('passport.private_key');

        if (is_string($configured) && $configured !== '') {
            return str_replace('\\n', "\n", $configured);
        }

        $path = Passport::keyPath('oauth-private.key');

        if (! $this->files->exists($path)) {
            throw new RuntimeException('No OpenID signing key found. Run "php artisan passport:keys" or "php artisan openid-provider:rotate-keys --prepare".');
        }

        return $this->files->get($path);
    }

    /**
     * Absolute path of a slot's key file.
     */
    public function path(string $slot): string
    {
        if (! in_array($slot, [self::CURRENT, self::NEXT, self::PREVIOUS], true)) {
            throw new RuntimeException("Unknown key slot [{$slot}].");
        }

        return $this->directory().DIRECTORY_SEPARATOR.$slot.'.key';
    }

    /**
     * Drop cached keys so the next call reads the files again.
     */
    public function forgetLoaded(): void
    {
        $this->loaded = [];
    }

    /**
     * Remember the kid a key was published under, so it keeps that kid in
     * its new slot (used when the current key had a configured kid).
     */
    public function writeKid(string $slot, string $kid): void
    {
        $this->files->ensureDirectoryExists($this->directory(), 0700);
        $this->files->put($this->path($slot).'.kid', $kid);
        $this->forgetLoaded();
    }

    /**
     * Forget a remembered kid.
     */
    public function deleteKid(string $slot): void
    {
        $this->files->delete($this->path($slot).'.kid');
        $this->forgetLoaded();
    }

    private function optional(string $slot): ?SigningKey
    {
        if (! array_key_exists($slot, $this->loaded)) {
            $pem = $this->read($slot);
            $kidPath = $this->path($slot).'.kid';
            $kid = $this->files->exists($kidPath) ? trim($this->files->get($kidPath)) : null;
            $this->loaded[$slot] = $pem === null ? null : new SigningKey($pem, $kid);
        }

        return $this->loaded[$slot];
    }

    private function directory(): string
    {
        $directory = config('openid-provider.keys.directory');

        return rtrim(is_string($directory) && $directory !== '' ? $directory : storage_path('openid-provider'), '/\\');
    }
}
