<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\ProposalVersion;
use App\Services\Ai\ProposalLinter;

/**
 * The single funnel every proposal-version write goes through - the AI
 * pipeline (LeadRefreshService::rewrite), the manual-edit endpoint, and any
 * future editor all call record(). Routing every write through one place is
 * what makes "the safeguards run on EVERY version" true by construction: this
 * class always runs ProposalLinter::check() and persists the result, so no
 * code path can slip a version in without it being rule-checked.
 *
 * Rows are append-only. record() never updates an existing version; it always
 * writes the next version_number for the lead.
 */
class ProposalVersionRecorder
{
    public function __construct(protected ProposalLinter $linter) {}

    /**
     * Snapshot the given body as the lead's next proposal version, running the
     * linter against the job brief (so tech-claim and trap-instruction checks
     * run too, not just word count) and recording which rules this version
     * newly fixed versus the one before it.
     */
    public function record(
        Lead $lead,
        string $body,
        string $editType,
        ?string $model = null,
        ?int $userId = null,
    ): ProposalVersion {
        $violations = $this->linter->check($body, $lead->full_brief);

        // reorder() clears the relation's default ascending order so we get
        // the newest row, not the oldest, as the baseline for "rules fixed".
        $previous = $lead->proposalVersions()->reorder('version_number', 'desc')->first();
        $previousViolations = $previous?->linter_violations ?? [];
        $fixed = array_values(array_diff($previousViolations, $violations));

        $nextNumber = (int) $lead->proposalVersions()->max('version_number') + 1;

        return $lead->proposalVersions()->create([
            'version_number' => $nextNumber,
            'edit_type' => $editType,
            'body' => $body,
            'word_count' => $this->wordCount($body),
            'char_count' => mb_strlen($body),
            'linter_violation_count' => count($violations),
            'linter_violations' => $violations !== [] ? array_values($violations) : null,
            'linter_rules_fixed' => $fixed !== [] ? $fixed : null,
            'model' => $model,
            'created_by' => $userId,
        ]);
    }

    /**
     * Freeze the lead's current latest version as the one actually sent, so a
     * later edit to proposal_text never rewrites the historical record. No-op
     * when the lead has no versions yet (e.g. a proposal generated before this
     * feature existed) - the export falls back to the live proposal_text.
     */
    public function markLatestSent(Lead $lead): ?ProposalVersion
    {
        $latest = $lead->proposalVersions()->reorder('version_number', 'desc')->first();

        if ($latest === null || $latest->is_sent) {
            return $latest;
        }

        $latest->update([
            'is_sent' => true,
            'is_locked' => true,
            'sent_at' => now(),
        ]);

        return $latest;
    }

    protected function wordCount(string $body): int
    {
        return count(preg_split('/\s+/u', trim($body), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
