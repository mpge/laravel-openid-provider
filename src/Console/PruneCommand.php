<?php

namespace Mpge\OpenIdProvider\Console;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Laravel\Passport\Passport;

/**
 * Removes grant bookkeeping rows that no longer protect anything.
 *
 * Run it after `passport:purge`, which deletes the expired and revoked
 * tokens and codes themselves. A link row is removed once its access token
 * is gone; a grant is removed once no access token links to it and its
 * authorization code row is gone. Only rows older than --hours are touched,
 * so a grant whose code was just issued is never removed.
 */
class PruneCommand extends Command
{
    protected $signature = 'openid-provider:prune
        {--hours=168 : Only remove rows created more than this many hours ago}';

    protected $description = 'Remove OpenID provider grant records whose tokens and codes have been purged';

    public function handle(DatabaseManager $db): int
    {
        $hours = $this->option('hours');

        if (! is_string($hours) || ! ctype_digit($hours)) {
            $this->components->error('--hours must be a whole number.');

            return self::FAILURE;
        }

        $cutoff = Date::now()->subHours((int) $hours);
        $name = config('passport.connection');
        $connection = $db->connection(is_string($name) ? $name : null);

        $tokens = Passport::token()->getTable();
        $codes = Passport::authCode()->getTable();

        $links = $connection->table('openid_provider_grant_tokens')
            ->where('created_at', '<', $cutoff)
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')
                ->from($tokens)
                ->whereColumn("{$tokens}.id", 'openid_provider_grant_tokens.access_token_id'))
            ->delete();

        $grants = $connection->table('openid_provider_grants')
            ->where('created_at', '<', $cutoff)
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('openid_provider_grant_tokens')
                ->whereColumn('openid_provider_grant_tokens.grant_id', 'openid_provider_grants.id'))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')
                ->from($codes)
                ->whereColumn("{$codes}.id", 'openid_provider_grants.id'))
            ->delete();

        $this->components->info("Removed {$grants} grants and {$links} token links.");

        return self::SUCCESS;
    }
}
