<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per AI API call — the cost ledger. Token counts come straight
 * from the provider's usage object, never estimated client-side.
 */
#[Fillable([
    'purpose', 'lead_id', 'provider', 'model', 'input_tokens', 'output_tokens',
    'cached_tokens', 'cache_write_tokens', 'cost_usd', 'duration_ms', 'success', 'error',
])]
class AiCall extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (self $call) {
            $call->created_at ??= now();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'cost_usd' => 'float',
            'created_at' => 'datetime',
        ];
    }
}
