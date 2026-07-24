<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\LeadOutcome;
use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\AiCall;
use App\Models\Lead;
use App\Services\Ai\ProposalService;
use App\Services\Ai\ScoringService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * THE one brain's two manual triggers — rescore and rewrite — shared by
 * every caller (dashboard buttons, Agent API / WhatsApp). One pipeline,
 * multiple mouths: rules always come from Settings at the moment of the
 * run, never from the caller, so there is nothing to sync and no second
 * copy to drift. Callers pass only a lead and a source label for the
 * audit trail.
 *
 * A per-lead lock guards both actions across all callers: two rescores
 * (or a rescore racing a rewrite) on the same lead would double AI spend
 * and end in a last-writer-wins scramble, so the second caller gets a
 * LeadRunInProgressException to translate into a 409.
 */
class LeadRefreshService
{
    public const LOCK_SECONDS = 300;

    public function __construct(
        protected ScoringService $scoring,
        protected ProposalService $proposals,
        protected ProposalVersionRecorder $versions,
    ) {}

    /**
     * @return array{id: int, score: int, score_reason: string, sub_scores: array<string, int>|null, bid: bool, boost: bool, model_used: string, cost: float|null, duration_ms: int}
     *
     * @throws LeadRunInProgressException
     */
    public function rescore(Lead $lead, string $source): array
    {
        return $this->locked($lead, function () use ($lead, $source) {
            $startedAt = microtime(true);
            $result = $this->scoring->score($lead);

            $updates = [
                'score' => $result['score'],
                'score_reason' => $result['reason'],
                'sub_scores' => $result['sub_scores'],
                'boost' => $result['boost'],
            ];

            // A lead still in the intake stages gets its status set by the
            // verdict so it lands in the right list; a lead already worked
            // (ready, sent, replied, won) or deliberately archived keeps
            // its status — re-scoring must never demote or resurrect it.
            if (in_array($lead->status, [LeadStatus::New, LeadStatus::Scoring, LeadStatus::NeedsReview], true)) {
                $updates['status'] = $result['bid'] ? LeadStatus::Ready : LeadStatus::Archived;
                $updates['outcome'] = $result['bid'] ? null : LeadOutcome::AutoFiltered;
                $updates['outcome_at'] = $result['bid'] ? null : now();
            }

            $lead->update($updates);

            ActivityLog::record(ActivityType::LeadScored, subject: $lead, meta: [
                'score' => $result['score'],
                'boost' => $result['boost'],
                'manual' => true,
                'source' => $source,
            ]);

            return [
                'id' => $lead->id,
                'score' => $result['score'],
                'score_reason' => $result['reason'],
                'sub_scores' => $result['sub_scores'],
                'bid' => $result['bid'],
                'boost' => $result['boost'],
                'model_used' => $result['response']->model,
                'cost' => $result['response']->costUsd,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        });
    }

    /**
     * @return array{id: int, proposal_text: string, quality_warnings: array<int, string>, versions_tried: int, model_used: string, cost: float|null, duration_ms: int}
     *
     * @throws LeadRunInProgressException
     */
    public function rewrite(Lead $lead, string $source): array
    {
        return $this->locked($lead, function () use ($lead, $source) {
            $startedAt = microtime(true);
            $callsBefore = (int) AiCall::where('lead_id', $lead->id)->max('id');

            $result = $this->proposals->write($lead, [
                'score' => $lead->score ?? 7,
                'boost' => (bool) $lead->boost,
                'reason' => (string) $lead->score_reason,
            ]);

            // Whether this is the lead's first-ever proposal or a rewrite is
            // decided before the update, from whether any version already
            // exists - proposal_text alone can't tell them apart.
            $editType = $lead->proposalVersions()->exists() ? 'rewrite' : 'initial_draft';

            $lead->update([
                'proposal_text' => $result['text'],
                'proposal_warnings' => $result['warnings'] !== [] ? $result['warnings'] : null,
            ]);

            // Append the immutable snapshot. The recorder re-runs the linter,
            // so the version's stored violations are computed independently of
            // the pipeline's shipped warnings - one source of truth for "what
            // rules did this text break".
            $this->versions->record($lead, $result['text'], $editType, $result['response']->model, Auth::id());

            ActivityLog::record('proposal_regenerated', subject: $lead, meta: [
                'shipped_rule' => $result['shipped_rule'],
                'revisions' => $result['revisions'],
                'source' => $source,
            ]);

            // The run's true spend: every call it made (draft, reviews,
            // revisions, surgical fix), not just the shipped version's.
            $cost = AiCall::where('lead_id', $lead->id)->where('id', '>', $callsBefore)->sum('cost_usd');

            return [
                'id' => $lead->id,
                'proposal_text' => $result['text'],
                'quality_warnings' => $result['warnings'],
                'versions_tried' => count($result['versions']),
                'model_used' => $result['response']->model,
                'cost' => $cost !== null ? round((float) $cost, 6) : null,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $run
     * @return T
     *
     * @throws LeadRunInProgressException
     */
    protected function locked(Lead $lead, callable $run)
    {
        // The quality-gate rewrite can run 40-80s, past the host proxy's
        // ~60s ceiling. When the proxy 504s and drops the client (e.g. a
        // WhatsApp rewrite), the run must FINISH and save anyway, so the
        // "check the dashboard" fallback is honest instead of a lost run.
        // ignore_user_abort keeps PHP going after the disconnect; the time
        // limit covers the full pipeline.
        ignore_user_abort(true);
        @set_time_limit(180);

        $lock = Cache::lock("lead-refresh:{$lead->id}", self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new LeadRunInProgressException("A rescore or rewrite is already running for lead {$lead->id}.");
        }

        try {
            return $run();
        } finally {
            $lock->release();
        }
    }
}
