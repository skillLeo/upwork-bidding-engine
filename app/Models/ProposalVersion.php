<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

/**
 * One immutable snapshot of a lead's proposal at a point in time. See the
 * create_proposal_versions_table migration for why this hangs off leads.id
 * rather than a (nonexistent) proposals table.
 */
#[Fillable([
    'lead_id', 'version_number', 'edit_type', 'body', 'word_count', 'char_count',
    'linter_violation_count', 'linter_violations', 'linter_rules_fixed', 'model',
    'is_sent', 'sent_at', 'is_locked', 'created_by',
])]
class ProposalVersion extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'linter_violations' => 'array',
            'linter_rules_fixed' => 'array',
            'is_sent' => 'boolean',
            'is_locked' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
