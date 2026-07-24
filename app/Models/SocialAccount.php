<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A linked OAuth identity (Google today). Keyed by user, not tenant — see
 * TenancyGuardTest::NON_TENANT_TABLES for why this table carries no
 * tenant_id: a person's Google identity is workspace-independent, same as
 * their password.
 */
#[Fillable(['user_id', 'provider', 'provider_id', 'linked_at'])]
class SocialAccount extends Model
{
    protected function casts(): array
    {
        return ['linked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
