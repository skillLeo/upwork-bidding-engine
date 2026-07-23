<?php

namespace App\Models;

use App\Enums\LeadOutcome;
use App\Enums\LeadStatus;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'external_id', 'title', 'full_brief', 'skills', 'url', 'budget', 'budget_min', 'budget_max', 'budget_type',
    'client_country', 'client_spend', 'client_spend_amount', 'client_hire_rate', 'client_hire_rate_pct', 'client_rating', 'client_reviews', 'payment_verified',
    'proposal_count', 'connects_required', 'score', 'boost', 'score_reason', 'sub_scores', 'proposal_text', 'proposal_warnings',
    'notification_skipped_reason', 'notify_error', 'status', 'is_favorite',
    'client_id', 'posted_at', 'submitted_at', 'viewed_at', 'outcome', 'outcome_at',
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
            'sub_scores' => 'array',
            'proposal_count' => 'integer',
            'connects_required' => 'integer',
            'client_hire_rate_pct' => 'float',
            'score' => 'integer',
            'boost' => 'boolean',
            'status' => LeadStatus::class,
            'outcome' => LeadOutcome::class,
            'posted_at' => 'datetime',
            'submitted_at' => 'datetime',
            'viewed_at' => 'datetime',
            'outcome_at' => 'datetime',
        ];
    }

    /**
     * A lead's FIRST transition to Sent stamps submitted_at, once, regardless
     * of which caller changed the status (the dashboard, the Agent API, a
     * tinker script). A later re-send never overwrites the original time -
     * that's the honest "when did this first go out" record everything in
     * Analytics' Speed panel is built on. Bulk status changes bypass Eloquent
     * events entirely (a raw query builder update), so bulkStatus() in
     * LeadController stamps separately for that path - see the comment there.
     */
    protected static function booted(): void
    {
        static::updating(function (Lead $lead) {
            if ($lead->isDirty('status') && $lead->status === LeadStatus::Sent && $lead->submitted_at === null) {
                $lead->submitted_at = now();
            }
        });
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Append-only proposal history, newest last by version_number.
     *
     * @return HasMany<ProposalVersion, $this>
     */
    public function proposalVersions(): HasMany
    {
        return $this->hasMany(ProposalVersion::class)->orderBy('version_number');
    }
}
