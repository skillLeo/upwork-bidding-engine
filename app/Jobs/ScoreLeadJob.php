<?php

namespace App\Jobs;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Services\OpenClawService;
use App\Services\ScoringService;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScoreLeadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $leadId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(ScoringService $scoring, OpenClawService $openClaw, SettingsService $settings): void
    {
        $lead = Lead::find($this->leadId);

        if (! $lead || ! in_array($lead->status, [LeadStatus::New, LeadStatus::Scoring], true)) {
            return;
        }

        $filter = $scoring->applyHardFilters($lead);

        if (! $filter['passed']) {
            $lead->update([
                'status' => LeadStatus::Archived,
                'score_reason' => $filter['reason'],
            ]);

            ActivityLog::record(ActivityType::LeadFiltered, subject: $lead, meta: [
                'reason' => $filter['reason'],
            ]);

            return;
        }

        // Kill switch: leave the lead as `new` (untouched, still visible, not
        // consumed) rather than call OpenClaw. Vollna intake keeps working
        // either way — only AI processing pauses. Re-enabling requires a
        // manual rescore for leads that arrived while this was off, since
        // this dispatch is already spent.
        if (! $settings->aiEngineEnabled()) {
            ActivityLog::record('ai_engine_disabled', subject: $lead);

            return;
        }

        $lead->update(['status' => LeadStatus::Scoring]);

        try {
            $result = $openClaw->scoreAndWrite($lead, $settings->rules());
        } catch (\Throwable $e) {
            report($e);

            ActivityLog::record(ActivityType::LeadScoringFailed, subject: $lead, meta: [
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Re-throw so the queue retries with the backoff schedule above.
            throw $e;
        }

        $cutoff = $settings->get('score_cutoff', 7);
        $isReady = $result['score'] >= (int) $cutoff;

        $lead->update([
            'score' => $result['score'],
            'score_reason' => $result['reason'],
            'proposal_text' => $result['proposal'],
            'status' => $isReady ? LeadStatus::Ready : LeadStatus::Archived,
        ]);

        ActivityLog::record(ActivityType::LeadScored, subject: $lead, meta: [
            'score' => $result['score'],
            'ready' => $isReady,
        ]);

        if ($isReady) {
            NotifyBidderJob::dispatch($lead->id);
        }
    }

    /**
     * Final failure after all retries are exhausted. Goes back to `new`
     * (not `archived`) — archiving a lead we never actually evaluated would
     * hide it from view and read as "we looked at this and passed," when
     * really the AI call itself never completed. `new` keeps it in the
     * default board view with the error visible in score_reason, and
     * eligible for a manual rescore once whatever broke is fixed.
     */
    public function failed(\Throwable $exception): void
    {
        $lead = Lead::find($this->leadId);

        if (! $lead) {
            return;
        }

        $lead->update([
            'status' => LeadStatus::New,
            'score_reason' => 'Scoring failed after retries: '.$exception->getMessage(),
        ]);

        ActivityLog::record(ActivityType::LeadScoringFailed, subject: $lead, meta: [
            'error' => $exception->getMessage(),
            'final' => true,
        ]);
    }
}
