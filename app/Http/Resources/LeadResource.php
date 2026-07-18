<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Lead
 */
class LeadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'title' => $this->title,
            'full_brief' => $this->full_brief,
            'skills' => $this->skills ?? [],
            'url' => $this->url,
            'budget' => $this->budget,
            'budget_min' => $this->budget_min,
            'budget_max' => $this->budget_max,
            'budget_type' => $this->budget_type,
            'client_country' => $this->client_country,
            'client_spend' => $this->client_spend,
            'client_spend_amount' => $this->client_spend_amount,
            'client_hire_rate' => $this->client_hire_rate,
            'client_hire_rate_pct' => $this->client_hire_rate_pct,
            'client_rating' => $this->client_rating,
            'client_reviews' => $this->client_reviews,
            'payment_verified' => $this->payment_verified,
            'proposal_count' => $this->proposal_count,
            'connects_required' => $this->connects_required,
            'score' => $this->score,
            'boost' => $this->boost,
            'score_reason' => $this->score_reason,
            'proposal_text' => $this->proposal_text,
            'proposal_warnings' => $this->proposal_warnings,
            'status' => $this->status->value,
            'is_favorite' => (bool) $this->is_favorite,
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client ? new ClientResource($this->client) : null),
            'posted_at' => $this->posted_at ->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            // Only set by LeadController when a filter's criteria are in
            // play (e.g. searching while a saved filter is active) - absent
            // entirely on a plain unfiltered browse.
            'matches_filter' => $this->when(
                $this->offsetExists('matches_filter'),
                fn () => $this->matches_filter,
            ),
            'filter_fail_reasons' => $this->when(
                $this->offsetExists('filter_fail_reasons'),
                fn () => $this->filter_fail_reasons,
            ),
        ];
    }
}
