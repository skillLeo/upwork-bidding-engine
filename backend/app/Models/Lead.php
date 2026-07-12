<?php

namespace App\Models;

use App\Enums\LeadStatus;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'external_id', 'title', 'full_brief', 'url', 'budget',
    'client_country', 'client_spend', 'client_hire_rate', 'payment_verified',
    'proposal_count', 'score', 'score_reason', 'proposal_text', 'status',
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
            'proposal_count' => 'integer',
            'score' => 'integer',
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
