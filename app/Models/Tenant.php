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
#[Fillable(['name', 'slug', 'specialization', 'plan', 'status', 'owner_user_id', 'trial_ends_at'])]
class Tenant extends Model
{
    use SoftDeletes;

    /**
     * The plan the platform's own workspace runs on. Features that exist only
     * for the company operating the product — the OpenClaw bridge and the AI
     * Engine settings tab — key off this and must never appear for a customer
     * workspace (P8).
     */
    public const PLAN_INTERNAL = 'internal';

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

    /**
     * Internal-only features (OpenClaw / the AI Engine tab) are on for this
     * workspace and off for every other. Deliberately a plan check and not a
     * settings flag: a settings flag is something a workspace could be handed
     * by accident, whereas the plan is set by the platform owner.
     */
    public function isInternal(): bool
    {
        return $this->plan === self::PLAN_INTERNAL;
    }

    /**
     * The company's own workspace — the one the platform operates from.
     *
     * Platform-wide work still needs A workspace bound: settings resolution,
     * activity logging and ops alerts are all tenant-owned, and the shared
     * lead intake is run by the company rather than by any customer. This is
     * the honest owner for that work, and pinning it to the internal plan
     * means it cannot drift onto a customer's workspace the way "tenant 1"
     * silently did.
     *
     * TENANCY: the tenants table is never tenant-scoped — it is the table
     * the scope is derived from.
     */
    public static function platformWorkspace(): ?self
    {
        // TENANCY: the tenants table is never tenant-scoped — it is the table
        // the scope is derived from.
        return \App\Tenancy\Tenancy::asPlatform(function () {
            $internal = static::query()->where('plan', self::PLAN_INTERNAL)->orderBy('id')->first();

            // A single-workspace install — a fresh deployment, or the test
            // suite — has no internal plan yet. The oldest workspace is the
            // company's own by definition there, and refusing intake outright
            // would be a worse answer than operating from it.
            //
            // Safe in a way the old implicit "tenant 1" fallback was not:
            // this only decides whose SETTINGS and activity log intake runs
            // under. Ownership of the leads themselves is no longer at stake
            // — they go to the shared pool and out to every workspace.
            return $internal ?? static::query()->orderBy('id')->first();
        });
    }

    /**
     * DISPLAY ONLY. `specialization` is a label the workspace picks for
     * itself ("Laravel + Vue", "Graphic design") and nothing reads it to make
     * a decision. Real scoring behaviour comes from the workspace's
     * core/secondary/excluded stack lists — see the column's migration for
     * why there is deliberately no second source of truth here.
     */
    public function specializationLabel(): ?string
    {
        return $this->specialization;
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
