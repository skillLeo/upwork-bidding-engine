<?php

namespace App\Services\Workspaces;

use App\Services\SettingsService;
use App\Tenancy\Tenancy;

/**
 * Is this workspace configured enough to score and write for?
 *
 * WHY THIS HAS TO EXIST. Until now, a workspace that had entered nothing
 * still scored — because the hardcoded schema defaults were the founding
 * user's own stacks, project facts and signature. Every new workspace was
 * born as a copy of him: it scored against his stack lists and could claim
 * his track record in its proposals. Those defaults are now empty, which is
 * correct, and which creates this problem: a workspace with no stacks would
 * otherwise send a rubric reading "CORE STACKS: none listed" to a paid model
 * and score every lead as out of scope.
 *
 * So scoring is not attempted at all until the minimum is met. Leads still
 * arrive, dedupe and save — nothing is lost, and the moment the owner fills
 * the gaps a rescore picks them all up.
 *
 * Deliberately its own class with its own tests: the onboarding wizard needs
 * exactly this "are they finished?" answer, and two implementations of it
 * would drift.
 */
class WorkspaceReadiness
{
    public function __construct(protected SettingsService $settings) {}

    /**
     * The workspace can be scored against: it has said what it does.
     *
     * EXACTLY ONE REQUIREMENT, and deliberately so. The core stack list is
     * the only thing here that nobody can pick a sensible default for — it
     * is the whole definition of what work is in scope, and getting it wrong
     * means rejecting every job in the workspace's own speciality.
     *
     * The budget floors are NOT on this list even though they matter. Their
     * shipped defaults are generic numbers rather than anyone's personal
     * values, so a workspace that never touches them is configured, not
     * broken — and min_budget = 0 is a deliberate "no floor at all", not an
     * empty field. Treating zero as unset would refuse to score for a
     * workspace that had chosen exactly that.
     *
     * @return array<int, array{key: string, label: string, section: string}>
     *                                                                        Empty when ready; otherwise what is missing.
     */
    public function missingForScoring(): array
    {
        $missing = [];

        if ($this->stackList('core_stacks') === []) {
            $missing[] = [
                'key' => 'core_stacks',
                'label' => 'At least one core stack — what you actually build',
                'section' => 'rules',
            ];
        }

        return $missing;
    }

    /**
     * Everything scoring needs, plus what a proposal needs before it can
     * claim anything: a track record to draw on and a name to sign off with.
     *
     * @return array<int, array{key: string, label: string, section: string}>
     */
    public function missingForProposals(): array
    {
        $missing = $this->missingForScoring();

        if (trim((string) $this->settings->get('project_facts')) === '') {
            $missing[] = [
                'key' => 'project_facts',
                'label' => 'Your project facts — a proposal may only claim what this sheet backs',
                'section' => 'track-record',
            ];
        }

        if (trim((string) $this->settings->get('proposal_signature')) === '') {
            $missing[] = [
                'key' => 'proposal_signature',
                'label' => 'The name your proposals sign off with',
                'section' => 'track-record',
            ];
        }

        return $missing;
    }

    public function canScore(): bool
    {
        return $this->missingForScoring() === [];
    }

    public function canWriteProposals(): bool
    {
        return $this->missingForProposals() === [];
    }

    /**
     * One line naming what is missing, for the lead's own score_reason — so
     * an unscored lead explains itself on the row rather than looking broken.
     */
    public function reason(): string
    {
        $missing = $this->missingForScoring();

        if ($missing === []) {
            return '';
        }

        return 'Workspace setup incomplete: '
            .implode('; ', array_column($missing, 'label'))
            .'. Leads are still being collected and will score once this is filled in.';
    }

    /**
     * The dashboard banner payload. Null when there is nothing to say —
     * the banner clears itself the moment the minimums are met.
     *
     * @return array{missing: array<int, array{key: string, label: string, section: string}>, can_score: bool, can_write_proposals: bool, workspace: ?string}|null
     */
    public function banner(): ?array
    {
        $missing = $this->missingForProposals();

        if ($missing === []) {
            return null;
        }

        return [
            'missing' => $missing,
            'can_score' => $this->canScore(),
            'can_write_proposals' => false,
            'workspace' => Tenancy::current()?->name,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function stackList(string $key): array
    {
        return array_values(array_filter(
            array_map('trim', (array) $this->settings->get($key, [])),
            fn (string $v) => $v !== '',
        ));
    }
}
