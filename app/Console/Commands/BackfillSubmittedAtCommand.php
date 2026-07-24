<?php

namespace App\Console\Commands;

use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\Lead;
use Illuminate\Console\Command;

/**
 * One-time, manually-run backfill for leads that reached Sent before
 * submitted_at existed. Sourced ONLY from a real activity_log status-change
 * entry for that exact lead - never estimated, never defaulted to
 * created_at or posted_at, which would fabricate a speed number that never
 * happened. A lead with no such entry is left NULL on purpose.
 */
class BackfillSubmittedAtCommand extends Command
{
    protected $signature = 'leads:backfill-submitted-at';

    protected $description = 'Backfill submitted_at for already-sent leads from their real activity_log status-change entry';

    public function handle(): int
    {
        $candidates = Lead::query()
            ->whereIn('status', LeadStatus::sentOrBeyond())
            ->whereNull('submitted_at')
            ->get(['id']);

        $backfilled = 0;
        $left = 0;

        foreach ($candidates as $lead) {
            $event = ActivityLog::query()
                ->where('subject_type', Lead::class)
                ->where('subject_id', $lead->id)
                ->where('type', 'lead_status_updated')
                ->whereJsonContains('meta->to', 'sent')
                ->oldest('id')
                ->first();

            if ($event === null) {
                $left++;

                continue;
            }

            Lead::whereKey($lead->id)->update(['submitted_at' => $event->created_at]);
            $backfilled++;
        }

        $this->info("Backfilled: {$backfilled}. Left null (no matching activity_log entry found): {$left}.");

        return self::SUCCESS;
    }
}
