<?php

namespace App\Console\Commands;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\DeletedLeadExternalId;
use App\Models\Lead;
use Illuminate\Console\Command;

/**
 * Permanently removes stale, never-acted-on leads and tombstones their
 * external_id so a later Vollna poll/backfill/mirror sync can never
 * recreate them (see deleted_lead_external_ids migration for the incident
 * this closes - a mirror sync once resurrected 515 deleted leads).
 *
 * Deliberately excludes sent/replied/won and favorited leads regardless of
 * age - those are real bidding history, not backlog, and score
 * calibration on the Analytics page depends on keeping them.
 */
class PruneOldLeadsCommand extends Command
{
    protected $signature = 'leads:prune {--hours=2 : Delete leads posted more than this many hours ago}';

    protected $description = 'Permanently delete stale unactioned leads and block them from ever being re-synced';

    /** Connects were spent on every one of these — they are permanent records, never pruned. */
    protected static function protectedStatuses(): array
    {
        return LeadStatus::sentOrBeyond();
    }

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoff = now()->subHours($hours);

        $query = Lead::query()
            ->whereNotNull('posted_at')
            ->where('posted_at', '<', $cutoff)
            ->whereNotIn('status', array_map(fn ($s) => $s->value, self::protectedStatuses()))
            ->where('is_favorite', false);

        $total = $query->count();

        if ($total === 0) {
            $this->info("No leads older than {$hours}h to prune.");

            return self::SUCCESS;
        }

        $deleted = 0;

        $query->chunkById(200, function ($leads) use (&$deleted) {
            $externalIds = $leads->pluck('external_id')->filter()->unique()->values();

            // insertOrIgnore: a lead can only be tombstoned once, and this
            // must never fail the whole batch on a duplicate.
            if ($externalIds->isNotEmpty()) {
                DeletedLeadExternalId::query()->insertOrIgnore(
                    $externalIds->map(fn ($id) => [
                        'external_id' => $id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all()
                );
            }

            Lead::whereIn('id', $leads->pluck('id'))->delete();
            $deleted += $leads->count();
        });

        ActivityLog::record(ActivityType::LeadPruned, meta: [
            'count' => $deleted,
            'cutoff_hours' => $hours,
        ]);

        $this->info("Permanently deleted {$deleted} lead(s) posted more than {$hours}h ago. They are tombstoned and will not be re-synced.");

        return self::SUCCESS;
    }
}
