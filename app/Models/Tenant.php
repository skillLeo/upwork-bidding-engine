<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer workspace. Deliberately NOT tenant-scoped itself — this is the
 * table the scope is derived from.
 *
 * SoftDeletes backs the Workspace tab's "Delete workspace" (P5): a deleted
 * tenant disappears from every plain Tenant::query() app-wide (subdomain
 * resolution, the scheduler's tenant loop, login) the instant this lands, so
 * the workspace simply stops functioning — without one irreversible
 * cascading DELETE across a dozen tables. The platform console explicitly
 * withTrashed()s to still show it existed.
 */
#[Fillable(['name', 'slug', 'plan', 'status', 'owner_user_id', 'trial_ends_at'])]
class Tenant extends Model
{
    use SoftDeletes;

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
            'deleted_at' => 'datetime',
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

    /**
     * Suspended OR past_due — the narrower "no AI spend, no polling" gate
     * used by AiManager and VollnaPollApiCommand specifically (P5).
     * Deliberately separate from isOperable(): past_due still runs health
     * checks and reminders (a billing grace period, not a data one) but must
     * not run up further AI cost or fresh intake while payment is outstanding.
     */
    public function isBillingBlocked(): bool
    {
        return in_array($this->status, [self::STATUS_SUSPENDED, self::STATUS_PAST_DUE], true);
    }
}
