<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A customer workspace. Deliberately NOT tenant-scoped itself — this is the
 * table the scope is derived from.
 */
#[Fillable(['name', 'slug', 'plan', 'status', 'owner_user_id', 'trial_ends_at'])]
class Tenant extends Model
{
    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')->withPivot('joined_at');
    }

    /**
     * A suspended tenant is refused at the door and skipped by every poller,
     * so a non-paying workspace can never spend AI credit.
     */
    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * Eligible for background work. past_due still runs — the grace period is
     * a billing decision, not a data one; only suspension stops the engine.
     */
    public function isOperable(): bool
    {
        return ! $this->isSuspended();
    }
}
