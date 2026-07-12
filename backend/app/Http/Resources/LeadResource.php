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
            'url' => $this->url,
            'budget' => $this->budget,
            'client_country' => $this->client_country,
            'client_spend' => $this->client_spend,
            'client_hire_rate' => $this->client_hire_rate,
            'client_rating' => $this->client_rating,
            'client_reviews' => $this->client_reviews,
            'payment_verified' => $this->payment_verified,
            'proposal_count' => $this->proposal_count,
            'score' => $this->score,
            'score_reason' => $this->score_reason,
            'proposal_text' => $this->proposal_text,
            'status' => $this->status->value,
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client ? new ClientResource($this->client) : null),
            'posted_at' => $this->posted_at ->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
