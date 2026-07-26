<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;
use Spatie\Permission\PermissionRegistrar;

/**
 * Wires Spatie's "team" to OUR tenant.
 *
 * Spatie's teams feature scopes every role and permission assignment by a
 * team id. Rather than have the app remember to call
 * setPermissionsTeamId() on every request, this resolver reads the team id
 * straight from TenantContext — the same singleton the global query scope
 * reads from. So the moment ResolveTenant binds a tenant, Spatie is scoped
 * to it too, with no second place to keep in sync.
 *
 * An explicit override (setPermissionsTeamId) still wins when set — the role
 * provisioner and tests use it to seed/act inside a specific tenant. When no
 * override is set it falls through to the resolved tenant.
 */
class TenantTeamResolver implements PermissionsTeamResolver
{
    protected int|string|null $override = null;

    protected bool $hasOverride = false;

    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        if ($id instanceof Model) {
            $id = $id->getKey();
        }

        // NULL CLEARS THE OVERRIDE rather than pinning "team null".
        //
        // This is the whole bug that made owner-invitation acceptance throw
        // RoleDoesNotExist. Callers pin a team, then restore what they found
        // before — and what they found before is usually null, because
        // nothing had overridden anything. Storing that null as an override
        // left hasOverride true forever, so getPermissionsTeamId() stopped
        // consulting TenantContext for the rest of the process and every
        // later role lookup searched team NULL, where no role exists.
        //
        // Nothing in this app has tenant-less roles, so "team null" is never
        // a meaningful target — only "no override" is.
        if ($id === null) {
            $this->forgetOverride();

            return;
        }

        $this->override = $id;
        $this->hasOverride = true;
    }

    public function forgetOverride(): void
    {
        $this->override = null;
        $this->hasOverride = false;
    }

    /**
     * Run $fn with Spatie's team pinned to $teamId, then restore.
     *
     * STATIC, AND IT GOES THROUGH PermissionRegistrar ON PURPOSE.
     *
     * PermissionRegistrar builds its resolver with `new` from the
     * `permission.team_resolver` config value — it does NOT resolve it from
     * the container. So `app(TenantTeamResolver::class)` hands back a
     * DIFFERENT OBJECT than the one Spatie actually consults, and calling
     * pin/clear on that instance changes nothing at all while looking
     * exactly like it should work. That is a silent no-op, which is the
     * worst kind of wrong, and it is why this helper exists rather than an
     * instance method anyone might reasonably reach for through app().
     *
     * The registrar's getPermissionsTeamId() answers with the bound tenant
     * when nothing is overridden, so restoring its answer pins that same
     * value — harmless, because it is what would have resolved anyway — and
     * restoring null now genuinely clears (see setPermissionsTeamId).
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $fn
     * @return TReturn
     */
    public static function pinnedTo(int|string $teamId, \Closure $fn): mixed
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId($teamId);

        try {
            return $fn();
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    public function getPermissionsTeamId(): int|string|null
    {
        if ($this->hasOverride) {
            return $this->override;
        }

        return app(TenantContext::class)->id();
    }
}
