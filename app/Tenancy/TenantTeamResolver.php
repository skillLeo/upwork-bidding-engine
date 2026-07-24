<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

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

        $this->override = $id;
        $this->hasOverride = true;
    }

    public function getPermissionsTeamId(): int|string|null
    {
        if ($this->hasOverride) {
            return $this->override;
        }

        return app(TenantContext::class)->id();
    }
}
