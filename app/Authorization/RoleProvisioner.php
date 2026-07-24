<?php

namespace App\Authorization;

use App\Models\Tenant;
use App\Tenancy\Tenancy;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the four fixed roles and their permission grants for a tenant.
 *
 * Called on tenant creation (and by the data migration for the existing
 * workspace). Idempotent — re-running heals a workspace whose roles drift
 * from the code definition rather than duplicating them, which also makes it
 * safe to re-run after the permission list changes in a future release.
 *
 * Spatie's team id comes from TenantTeamResolver, which reads TenantContext
 * — the SAME singleton the query scope reads from. So binding the tenant via
 * Tenancy::runAs() scopes both the ordinary queries and Spatie at once, with
 * no second mechanism to keep in sync and nothing to leak afterward.
 */
class RoleProvisioner
{
    /**
     * Permissions are global in Spatie (not team-scoped); only the
     * role↔permission and user↔role links are scoped by tenant. So the
     * permission rows exist once, created here.
     */
    public function ensureGlobalPermissions(): void
    {
        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function provision(Tenant $tenant): void
    {
        $this->ensureGlobalPermissions();

        Tenancy::runAs($tenant, function () {
            foreach (TenantRole::cases() as $roleEnum) {
                $role = Role::findOrCreate($roleEnum->value, 'web');
                // syncPermissions, not give, so a permission removed from the
                // code definition is actually removed on the next run.
                $role->syncPermissions($roleEnum->permissions());
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Provision every existing tenant — used by the data migration and safe
     * to run whenever the role definitions change.
     */
    public function provisionAll(): void
    {
        // TENANCY: provisioning spans every tenant by design; each provision()
        // call rebinds the specific tenant via runAs internally.
        Tenancy::asPlatform(function () {
            Tenant::query()->get()->each(fn (Tenant $tenant) => $this->provision($tenant));
        });
    }
}
