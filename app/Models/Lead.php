<?php

namespace App\Models;

use App\Enums\LeadStatus;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'external_id', 'title', 'full_brief', 'skills', 'url', 'budget', 'budget_min', 'budget_max', 'budget_type',
    'client_country', 'client_spend', 'client_spend_amount', 'client_hire_rate', 'client_hire_rate_pct', 'client_rating', 'client_reviews', 'payment_verified',
    'proposal_count', 'connects_required', 'score', 'boost', 'score_reason', 'proposal_text', 'proposal_warnings', 'status', 'is_favorite',
    'client_id', 'posted_at',
])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_verified' => 'boolean',
            'is_favorite' => 'boolean',
            'skills' => 'array',
            'proposal_warnings' => 'array',
            'proposal_count' => 'integer',
            'connects_required' => 'integer',
            'client_hire_rate_pct' => 'float',
            'score' => 'integer',
            'boost' => 'boolean',
            'status' => LeadStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
