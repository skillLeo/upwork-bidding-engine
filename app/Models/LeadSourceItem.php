<?php

namespace App\Models;

use Database\Factories\LeadSourceItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One job as the source published it — the facts, owned by nobody.
 *
 * DELIBERATELY NOT tenant-scoped, and the absence of BelongsToTenant here is
 * load-bearing rather than an omission. The pool is the one thing in the app
 * every workspace reads from in common; scoping it would empty every
 * workspace's intake at once, silently, the way the global unique on
 * leads.external_id emptied all but the first.
 *
 * Nothing here is ever a judgement. Score, status, favourite, proposal,
 * outcome and the CRM client all live on the workspace's own `leads` row,
 * because those are opinions and two workspaces are entitled to disagree
 * about the same job. If a column would ever differ between two workspaces
 * looking at the same posting, it does not belong in this table.
 */
#[Fillable([
    'external_id', 'source', 'title', 'full_brief', 'skills', 'url',
    'budget', 'budget_min', 'budget_max', 'budget_type',
    'client_country', 'client_spend', 'client_spend_amount',
    'client_hire_rate', 'client_hire_rate_pct', 'client_rating', 'client_reviews',
    'payment_verified', 'proposal_count', 'connects_required', 'posted_at', 'fanned_out_at',
])]
class LeadSourceItem extends Model
{
    /** @use HasFactory<LeadSourceItemFactory> */
    use HasFactory;

    /**
     * The columns copied verbatim onto a workspace's lead when the item is
     * projected. Named once, here, so the fan-out and the backfill can never
     * drift into disagreeing about what a "fact" is — and so adding a field
     * to the pool is a one-line change rather than a hunt.
     *
     * external_id is included: a workspace's lead keeps its own copy so
     * dedupe, the deleted-lead tombstone and every existing query keep
     * working against `leads` alone, without a join to this table.
     *
     * @var array<int, string>
     */
    public const PROJECTED_COLUMNS = [
        'external_id', 'title', 'full_brief', 'skills', 'url',
        'budget', 'budget_min', 'budget_max', 'budget_type',
        'client_country', 'client_spend', 'client_spend_amount',
        'client_hire_rate', 'client_hire_rate_pct', 'client_rating', 'client_reviews',
        'payment_verified', 'proposal_count', 'connects_required', 'posted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'payment_verified' => 'boolean',
            'proposal_count' => 'integer',
            'connects_required' => 'integer',
            'client_reviews' => 'integer',
            'client_hire_rate_pct' => 'float',
            'client_rating' => 'float',
            'budget_min' => 'float',
            'budget_max' => 'float',
            'client_spend_amount' => 'float',
            'posted_at' => 'datetime',
            'fanned_out_at' => 'datetime',
        ];
    }

    /**
     * Every workspace's copy of this job. Crosses tenants by definition, so
     * callers must be in platform context to read it — which is the point:
     * the relation is for the platform console and the fan-out, not for a
     * workspace looking at its own board.
     *
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'source_item_id');
    }

    /**
     * The attribute payload for a workspace's copy of this job.
     *
     * @return array<string, mixed>
     */
    public function projection(): array
    {
        $attributes = [];

        foreach (self::PROJECTED_COLUMNS as $column) {
            $value = $this->getAttribute($column);

            // NULLS ARE DROPPED, NOT COPIED, and this is load-bearing.
            //
            // `leads.payment_verified` and `leads.proposal_count` are NOT
            // NULL with database defaults, while Eloquent does not populate
            // defaults onto a model it has just created — so an item saved
            // without them holds null in memory, and copying that null makes
            // the insert fail with "Column 'payment_verified' cannot be
            // null". Inside LeadFanOut that surfaced as the workspace
            // silently receiving nothing.
            //
            // Omitting instead lets each column's own default apply, which
            // is the right answer for every column here: the nullable ones
            // default to null anyway, so nothing is lost either way.
            if ($value === null) {
                continue;
            }

            $attributes[$column] = $value;
        }

        $attributes['source_item_id'] = $this->id;

        return $attributes;
    }
}
