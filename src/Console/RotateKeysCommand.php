<?php

namespace Mpge\OpenIdProvider\Console;

use Illuminate\Console\Command;
use Mpge\OpenIdProvider\Keys\KeyStore;
use Mpge\OpenIdProvider\Keys\SigningKey;
use phpseclib4\Crypt\RSA;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Rotates the ID token signing key in three explicit steps.
 *
 *  1. --prepare  generate the next key and publish it in the JWKS;
 *  2. --promote  (after clients have refreshed their JWKS cache) sign with
 *                the next key; the old key stays published as "previous";
 *  3. --retire   (after the old key's ID tokens have expired) stop
 *                publishing the previous key.
 *
 * Run without options to see the current state. See docs/key-rotation.md.
 */
#[AsCommand(name: 'openid-provider:rotate-keys')]
class RotateKeysCommand extends Command
{
    protected $signature = 'openid-provider:rotate-keys
        {--prepare : Generate the next signing key and publish it}
        {--promote : Start signing with the next key}
        {--retire : Stop publishing the previous key}
        {--force : Replace an existing next key when preparing}';

    protected $description = 'Rotate the OpenID Connect ID token signing key';

    public function handle(KeyStore $keys): int
    {
        $steps = array_filter([
            'prepare' => $this->option('prepare'),
            'promote' => $this->option('promote'),
            'retire' => $this->option('retire'),
        ]);

        if (count($steps) > 1) {
            $this->components->error('Run one step at a time: --prepare, --promote or --retire.');

            return self::FAILURE;
        }

        try {
            return match (array_key_first($steps)) {
                'prepare' => $this->prepare($keys),
                'promote' => $this->promote($keys),
                'retire' => $this->retire($keys),
                default => $this->status($keys),
            };
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function prepare(KeyStore $keys): int
    {
        if ($keys->next() !== null && ! $this->option('force')) {
            $this->components->error('A next key already exists. Promote it, or use --force to replace it.');

            return self::FAILURE;
        }

        if ($keys->previous() !== null) {
            $this->components->warn('Retiring the previous key so no more than two keys are published.');
            $keys->delete(KeyStore::PREVIOUS);
            $keys->deleteKid(KeyStore::PREVIOUS);
        }

        $bits = max(2048, (int) config('openid-provider.keys.bits', 3072));
        $keys->write(KeyStore::NEXT, (string) RSA::createKey($bits));
        $keys->deleteKid(KeyStore::NEXT);

        $next = $keys->next();
        $this->components->info("Next key generated and published (kid {$next?->kid}).");
        $this->line('  Wait at least the JWKS cache time ('.config('openid-provider.keys.jwks_max_age', 3600).' seconds) before running --promote.');

        return self::SUCCESS;
    }

    private function promote(KeyStore $keys): int
    {
        $next = $keys->read(KeyStore::NEXT);

        if ($next === null) {
            $this->components->error('There is no next key. Run --prepare first.');

            return self::FAILURE;
        }

        $current = $keys->current();
        $currentPem = $keys->read(KeyStore::CURRENT) ?? $keys->passportPrivateKey();

        $keys->write(KeyStore::PREVIOUS, $currentPem);
        $keys->writeKid(KeyStore::PREVIOUS, $current->kid);
        $keys->write(KeyStore::CURRENT, $next);
        $keys->delete(KeyStore::NEXT);
        $keys->deleteKid(KeyStore::NEXT);

        if (config('openid-provider.keys.current_kid')) {
            $this->components->warn('OPENID_PROVIDER_CURRENT_KID is set. Remove it now, or the new key will be published under the old kid.');
        }

        $this->components->info('New key is now signing ID tokens (kid '.(new SigningKey($next))->kid.').');
        $this->line("  The old key (kid {$current->kid}) stays published as the previous key.");
        $this->line('  Run --retire once ID tokens it signed have expired ('.config('openid-provider.id_token_ttl', 3600).' seconds).');

        return self::SUCCESS;
    }

    private function retire(KeyStore $keys): int
    {
        if ($keys->previous() === null) {
            $this->components->info('There is no previous key to retire.');

            return self::SUCCESS;
        }

        $keys->delete(KeyStore::PREVIOUS);
        $keys->deleteKid(KeyStore::PREVIOUS);
        $this->components->info('Previous key retired.');

        return self::SUCCESS;
    }

    private function status(KeyStore $keys): int
    {
        $rows = [[
            'current',
            $keys->current()->kid,
            $keys->hasStoredCurrentKey() ? $keys->path(KeyStore::CURRENT) : 'Passport private key',
        ]];

        if (($next = $keys->next()) !== null) {
            $rows[] = ['next', $next->kid, $keys->path(KeyStore::NEXT)];
        }

        if (($previous = $keys->previous()) !== null) {
            $rows[] = ['previous', $previous->kid, $keys->path(KeyStore::PREVIOUS)];
        }

        $this->table(['Slot', 'kid', 'Source'], $rows);
        $this->line('Steps: --prepare, then --promote, then --retire. See docs/key-rotation.md.');

        return self::SUCCESS;
    }
}
