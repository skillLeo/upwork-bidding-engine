<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Str;

/**
 * Checks a single lead against a saved filter's criteria and explains WHY
 * it fails, if it does - shared by the "Not in filter" badge (search
 * results) and the lead detail page's "Why this job isn't in your
 * filter" box, so the two never drift out of sync with each other or
 * with LeadController's actual WHERE-clause filtering logic.
 */
class LeadFilterEvaluator
{
    /**
     * @param  array<string, mixed>  $criteria
     * @return array<int, string> empty array means the lead passes every criterion
     */
    public function reasons(Lead $lead, array $criteria): array
    {
        $reasons = [];

        $include = $criteria['include_keywords'] ?? [];
        if (! empty($include) && ! $this->matchesAnyKeyword($lead, $include)) {
            $reasons[] = 'Include keywords: matches none of '.implode(', ', $include).'.';
        }

        $exclude = $criteria['exclude_keywords'] ?? [];
        $matchedExcluded = $this->matchedKeywords($lead, $exclude);
        if ($matchedExcluded !== []) {
            $reasons[] = 'Excluded keywords: matches '.implode(', ', $matchedExcluded).'.';
        }

        if (isset($criteria['budget_min']) && $criteria['budget_min'] !== null) {
            if ($lead->budget_max !== null && (float) $lead->budget_max < (float) $criteria['budget_min']) {
                $reasons[] = 'Budget: below the minimum of $'.number_format((float) $criteria['budget_min']).'.';
            }
        }

        if (isset($criteria['budget_max']) && $criteria['budget_max'] !== null) {
            if ($lead->budget_min !== null && (float) $lead->budget_min > (float) $criteria['budget_max']) {
                $reasons[] = 'Budget: above the maximum of $'.number_format((float) $criteria['budget_max']).'.';
            }
        }

        if (! empty($criteria['payment_verified_only']) && ! $lead->payment_verified) {
            $reasons[] = 'Client: payment method is not verified.';
        }

        if (isset($criteria['min_client_spend']) && $criteria['min_client_spend'] !== null) {
            $spend = $lead->client_spend_amount;
            if ($spend === null || (float) $spend < (float) $criteria['min_client_spend']) {
                $reasons[] = 'Client: spend is below $'.number_format((float) $criteria['min_client_spend']).'.';
            }
        }

        $countriesIn = $criteria['client_countries_include'] ?? [];
        if (! empty($countriesIn) && ! in_array($lead->client_country, $countriesIn, true)) {
            $reasons[] = 'Client: country is not in your included list.';
        }

        $countriesOut = $criteria['client_countries_exclude'] ?? [];
        if (! empty($countriesOut) && in_array($lead->client_country, $countriesOut, true)) {
            $reasons[] = 'Client: country is in your excluded list.';
        }

        if (isset($criteria['posted_within_minutes']) && $criteria['posted_within_minutes'] !== null) {
            $cutoff = now()->subMinutes((int) $criteria['posted_within_minutes']);
            if (! $lead->posted_at || $lead->posted_at->lt($cutoff)) {
                $reasons[] = 'Freshness: posted before your freshness window.';
            }
        }

        return $reasons;
    }

    public function passes(Lead $lead, array $criteria): bool
    {
        return $this->reasons($lead, $criteria) === [];
    }

    /**
     * @param  array<int, string>  $keywords
     */
    protected function matchesAnyKeyword(Lead $lead, array $keywords): bool
    {
        return $this->matchedKeywords($lead, $keywords) !== [];
    }

    /**
     * @param  array<int, string>  $keywords
     * @return array<int, string>
     */
    protected function matchedKeywords(Lead $lead, array $keywords): array
    {
        $haystack = $lead->title.' '.$lead->full_brief;

        return array_values(array_filter(
            $keywords,
            fn (string $keyword) => $keyword !== '' && Str::contains($haystack, $keyword, ignoreCase: true),
        ));
    }
}
