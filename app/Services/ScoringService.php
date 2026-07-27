<?php

namespace App\Services;

use App\Models\Lead;

/**
 * Cheap, deterministic PHP-only filters that run before any paid AI call.
 * These exist purely to save money: a lead that fails here never reaches
 * OpenClaw/Claude.
 */
class ScoringService
{
    public function __construct(protected SettingsService $settings) {}

    /**
     * @return array{passed: bool, reason: ?string}
     */
    public function applyHardFilters(Lead $lead): array
    {
        $rules = $this->settings->rules();

        if ($rules['max_proposals'] > 0 && $lead->proposal_count > $rules['max_proposals']) {
            return [
                'passed' => false,
                'reason' => "Proposal count ({$lead->proposal_count}) exceeds max_proposals ({$rules['max_proposals']}).",
            ];
        }

        if (
            $rules['max_posted_age_days'] > 0
            && $lead->posted_at !== null
            && $lead->posted_at->lt(now()->subDays($rules['max_posted_age_days']))
        ) {
            $days = (int) $lead->posted_at->diffInDays(now());

            return [
                'passed' => false,
                'reason' => "Posted {$days} days ago, past max_posted_age_days ({$rules['max_posted_age_days']}) — too old to realistically win.",
            ];
        }

        $amount = $this->parseBudgetAmount($lead->budget);
        $isHourly = $lead->budget !== null && str_contains(strtolower($lead->budget), 'hr');

        if ($isHourly && $amount !== null && $amount < $rules['hourly_floor']) {
            return [
                'passed' => false,
                'reason' => "Hourly rate (\${$amount}/hr) is below hourly_floor (\${$rules['hourly_floor']}/hr).",
            ];
        }

        if (! $isHourly && $amount !== null && $amount < $rules['min_budget']) {
            return [
                'passed' => false,
                'reason' => "Budget (\${$amount}) is below min_budget (\${$rules['min_budget']}).",
            ];
        }

        if ($this->clientHasNoHistory($lead) && $amount !== null && $amount < $rules['zero_history_budget_floor']) {
            return [
                'passed' => false,
                'reason' => "Client has no hire history and budget (\${$amount}) is below zero_history_budget_floor (\${$rules['zero_history_budget_floor']}).",
            ];
        }

        $haystack = strtolower($lead->title.' '.$lead->full_brief);

        foreach ($rules['red_flag_words'] as $word) {
            $needle = strtolower(trim((string) $word));

            if ($needle !== '' && str_contains($haystack, $needle)) {
                return [
                    'passed' => false,
                    'reason' => "Brief contains red-flag phrase: \"{$word}\".",
                ];
            }
        }

        return ['passed' => true, 'reason' => null];
    }

    /**
     * Is this job in this workspace's line of work at all?
     *
     * WHY THIS EXISTS. Every workspace is served the same lead feed from one
     * Vollna subscription, so a graphic-design workspace receives the Laravel
     * jobs and a backend workspace receives the logo jobs. Scoring is what
     * costs money, and it costs it once per workspace per lead — without a
     * cheap check first, onboarding a tenth admin multiplies the AI bill
     * tenfold on leads nobody involved would ever bid.
     *
     * The hard filters above cannot do this job: they gate on budget,
     * proposal count, age and red-flag phrases, and deliberately say nothing
     * about stacks. The stack lists reach the model only inside the rubric
     * prompt — which means paying full price to be told "not your field".
     *
     * A REFUSAL HERE IS NOT A REJECTION. The lead stays on the board,
     * unarchived, in `new`, with this reason on the row; the owner can score
     * it by hand. That distinction is the whole point — filtering is the
     * workspace's business, and this only decides where the money goes.
     *
     * Falls open in every ambiguous case: no stack lists configured, or a
     * brief that names no technology at all, both count as relevant. A false
     * negative costs a real lead; a false positive costs one AI call.
     *
     * @return array{relevant: bool, reason: ?string}
     */
    public function stackRelevance(Lead $lead): array
    {
        if (! $this->settings->get('stack_gate_enabled', true)) {
            return ['relevant' => true, 'reason' => null];
        }

        $lists = $this->settings->stackLists();
        $wanted = array_merge($lists['core'], $lists['secondary']);

        // No stacks set is not "nothing matches", it is "we have not been
        // told yet". WorkspaceReadiness already holds scoring back in that
        // case, and silently skipping here would hide why.
        if ($wanted === []) {
            return ['relevant' => true, 'reason' => null];
        }

        // ONE POSITIVE TEST, and deliberately nothing else.
        //
        // excluded_stacks is NOT consulted here, though it is tempting. That
        // list means "a job that CORE-REQUIRES this is out of scope" — a
        // judgement about what the work centres on, which no keyword match
        // can make. Caught in testing: a Next.js job whose brief mentioned
        // GraphQL once in passing was refused by a workspace that builds
        // Next.js, because GraphQL sat on its excluded list. Real briefs
        // name adjacent technologies constantly; refusing on a mention would
        // quietly drop good leads and nobody would ever learn why.
        //
        // So the excluded list keeps doing its real work inside the scoring
        // rubric, where the model can weigh what the job is actually about.
        // This only asks the cheap question: is there any sign at all that
        // this is our kind of work? A false positive costs one AI call. A
        // false negative costs a lead — so the asymmetry decides the design.
        $haystack = strtolower(implode(' ', [
            $lead->title,
            implode(' ', (array) $lead->skills),
            $lead->full_brief,
        ]));

        foreach ($wanted as $term) {
            if ($this->mentions($haystack, $term)) {
                return ['relevant' => true, 'reason' => null];
            }
        }

        return [
            'relevant' => false,
            'reason' => 'Not auto-scored: nothing in this job matches your core or secondary stacks. '
                .'It is still here — score it by hand, or widen your stacks in Settings → Bidding rules.',
        ];
    }

    /**
     * Word-boundary match, so "go" does not match "google" and "r" does not
     * match every word in the brief. Falls back to a plain substring test for
     * terms containing punctuation ("node.js", "c++", "ci/cd"), where
     * \b behaves in ways nobody writing a stack list would predict.
     */
    protected function mentions(string $haystack, string $term): bool
    {
        $needle = strtolower(trim($term));

        if ($needle === '') {
            return false;
        }

        if (preg_match('/^[a-z0-9 ]+$/', $needle) !== 1) {
            return str_contains($haystack, $needle);
        }

        return preg_match('/\b'.preg_quote($needle, '/').'\b/', $haystack) === 1;
    }

    protected function parseBudgetAmount(?string $budget): ?float
    {
        if (! $budget) {
            return null;
        }

        if (preg_match('/[\d,]+(\.\d+)?/', $budget, $matches)) {
            return (float) str_replace(',', '', $matches[0]);
        }

        return null;
    }

    protected function clientHasNoHistory(Lead $lead): bool
    {
        $spend = trim((string) $lead->client_spend);

        return $spend === '' || $spend === '$0' || $spend === '0' || str_starts_with($spend, '$0.');
    }
}
