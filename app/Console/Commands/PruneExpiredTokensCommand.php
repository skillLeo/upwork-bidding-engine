<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Deletes tokens that are already dead, so personal_access_tokens does not
 * grow forever and the Devices screen shows only sessions that could still
 * be used.
 *
 * This is HOUSEKEEPING, NOT ENFORCEMENT — an important distinction. Both
 * expiry rules are enforced at the moment of use (Sanctum's own guard for
 * absolute age, TokenFreshness for idleness), so if this command
 * never runs again nothing becomes less secure. That matters on this host,
 * where every scheduled task rides one cron entry that has died before.
 *
 * Deliberately NOT tenant-scoped: personal_access_tokens is a framework auth
 * table and one user may hold tokens across several workspaces.
 */
class PruneExpiredTokensCommand extends Command
{
    protected $signature = 'auth:prune-tokens';

    protected $description = 'Delete access tokens past their absolute or idle expiry';

    public function handle(): int
    {
        $absoluteMinutes = (int) config('sanctum.expiration', 0);
        $idleDays = \App\Services\Auth\TokenFreshness::IDLE_DAYS;

        $absolute = 0;

        if ($absoluteMinutes > 0) {
            $absolute = PersonalAccessToken::where('created_at', '<', now()->subMinutes($absoluteMinutes))->delete();
        }

        // COALESCE semantics: a token never used is judged on when it was
        // issued, matching TokenFreshness exactly. Doing this in two
        // queries rather than one raw expression keeps it portable across
        // MySQL and the SQLite used in tests.
        $cutoff = now()->subDays($idleDays);

        $idle = PersonalAccessToken::whereNotNull('last_used_at')
            ->where('last_used_at', '<', $cutoff)
            ->delete();

        $idle += PersonalAccessToken::whereNull('last_used_at')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$absolute} expired and {$idle} idle token(s).");

        return self::SUCCESS;
    }
}
