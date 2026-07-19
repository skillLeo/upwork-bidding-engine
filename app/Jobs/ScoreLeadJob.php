<?php

namespace App\Jobs;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Services\Ai\ProposalService;
use App\Services\Ai\ScoringService as AiScoringService;
use App\Services\OpsAlertService;
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

    /**
     * Scoring + a quality-gated proposal (draft, review, up to two
     * revisions) can legitimately run past the queue worker's 60s
     * default — this job-level timeout overrides it. Must stay below
     * the queue connection's retry_after so a slow-but-alive run is
     * never handed to a second worker.
     */
    public int $timeout = 240;

    /**
     * $inline marks the synchronous first attempt made during the webhook
     * request itself. Its failure must NOT run the final-failure treatment
     * (needs_review + operator alert) because the importer immediately
     * queues a fresh job that owns the real retry cycle.
     */
    public function __construct(public int $leadId, public bool $inline = false) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 10];
    }

    public function handle(ScoringService $scoring, AiScoringService $aiScoring, ProposalService $proposals, SettingsService $settings): void
    {
        $lead = Lead::find($this->leadId);

        if (! $lead || ! in_array($lead->status, [LeadStatus::New, LeadStatus::Scoring, LeadStatus::NeedsReview], true)) {
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

        $startedAt = microtime(true);

        try {
            // Direct API scoring under the operator's full rubric (Settings).
            // The rubric's own BID verdict decides ready-vs-archived — and
            // the proposal model only runs for BID-yes leads, so a rejected
            // lead never pays for (or shows) a proposal.
            $result = $aiScoring->score($lead);
            $proposal = $result['bid'] ? $proposals->write($lead, $result) : null;
        } catch (\Throwable $e) {
            report($e);

            ActivityLog::record(ActivityType::LeadScoringFailed, subject: $lead, meta: [
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            // Re-throw so the queue retries with the backoff schedule above.
            throw $e;
        }

        $isReady = $result['bid'];

        $lead->update([
            'score' => $result['score'],
            'score_reason' => $result['reason'],
            'sub_scores' => $result['sub_scores'],
            'proposal_text' => $proposal['text'] ?? null,
            'proposal_warnings' => ($proposal['warnings'] ?? []) !== [] ? $proposal['warnings'] : null,
            'boost' => $result['boost'],
            'status' => $isReady ? LeadStatus::Ready : LeadStatus::Archived,
        ]);

        ActivityLog::record(ActivityType::LeadScored, subject: $lead, meta: [
            'score' => $result['score'],
            'ready' => $isReady,
            'boost' => $result['boost'],
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        if ($isReady) {
            // Sync, not queued: the whole point of scoring inline on the
            // webhook is that a bidder gets the WhatsApp alert within
            // seconds — queuing this step back up would reintroduce the
            // exact lag we just removed. A notify failure must not read as
            // a scoring failure though (the lead IS scored), so it falls
            // back to its own queued retry instead of bubbling up.
            try {
                NotifyBidderJob::dispatchSync($lead->id);
            } catch (\Throwable $e) {
                report($e);
                NotifyBidderJob::dispatch($lead->id)->delay(30);
            }
        }
    }

    /**
     * Final failure after all retries are exhausted. `needs_review`, never
     * `archived` — archiving a lead we never actually evaluated would hide
     * it and read as "we looked at this and passed," when really the AI
     * call itself never completed. Needs-review keeps it visible with the
     * error in score_reason, eligible for a manual rescore, and pings the
     * operator so an OpenClaw outage isn't discovered days later.
     */
    public function failed(\Throwable $exception): void
    {
        $lead = Lead::find($this->leadId);

        if (! $lead) {
            return;
        }

        if ($this->inline) {
            // The importer queues a fresh job when the inline attempt dies —
            // that job owns the retry cycle and the real final-failure
            // treatment below.
            return;
        }

        $lead->update([
            'status' => LeadStatus::NeedsReview,
            'score_reason' => 'Scoring failed after retries: '.$exception->getMessage(),
        ]);

        ActivityLog::record(ActivityType::LeadScoringFailed, subject: $lead, meta: [
            'error' => $exception->getMessage(),
            'final' => true,
        ]);

        // Once per incident, not once per lead: a dead API key during a
        // sync burst turned this into 29+ identical WhatsApp messages.
        // One alert opens the incident; the rest stay in the dashboard's
        // Needs review list; the flag self-clears after 30 minutes.
        if (\Illuminate\Support\Facades\Cache::add('ai:scoring_failure_alerted', now()->toIso8601String(), now()->addMinutes(30))) {
            app(OpsAlertService::class)->send(sprintf(
                "⚠️ SkillLeo: AI scoring is failing — e.g. lead \"%s\" was NOT scored after 3 attempts.\n\nError: %s\n\nFailed leads are marked Needs review in the dashboard; rescore them once the AI provider is healthy. Further scoring-failure alerts are muted for 30 minutes.",
                $lead->title,
                $exception->getMessage(),
            ));
        }
    }
}
