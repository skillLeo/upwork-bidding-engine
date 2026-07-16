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
