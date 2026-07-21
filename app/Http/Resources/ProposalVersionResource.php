<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ProposalVersion
 */
class ProposalVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version_number' => $this->version_number,
            'edit_type' => $this->edit_type,
            'body' => $this->body,
            'word_count' => $this->word_count,
            'char_count' => $this->char_count,
            'linter_violation_count' => $this->linter_violation_count,
            'linter_violations' => $this->linter_violations,
            'linter_rules_fixed' => $this->linter_rules_fixed,
            'model' => $this->model,
            'is_sent' => (bool) $this->is_sent,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
