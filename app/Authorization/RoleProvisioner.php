<?php

namespace App\Authorization;

use App\Models\Tenant;
use App\Tenancy\Tenancy;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the four base roles for a tenant and keeps the permission rows in
 * step with the code vocabulary.
 *
 * SEMANTICS CHANGED with the editable-permissions decision, and the change
 * is the whole point:
 *
 *   OWNER      — re-synced to ALL permissions on every provision. Locked.
 *                When a release adds new permissions, the owner has them
 *                immediately, so nothing in the product is ever unreachable.
 *
 *   OTHER ROLES — seeded with their code DEFAULTS only when the role does
 *                not exist yet. After that they are never touched here:
 *                whatever a workspace has edited them to through the Roles
 *                matrix survives every deploy. New permissions from a later
 *                release are simply NOT granted to them until someone with
 *                permissions.edit turns them on — deny-by-default is the
 *                safe direction, and the always-full owner is the recovery
 *                path.
 */
class RoleProvisioner
{
    /**
     * Permission rows are global in Spatie (not team-scoped); only the
     * role↔permission and user↔permission links carry a tenant. Includes the
     * setting.{key} permissions derived from the settings schema, so a new
     * settings key automatically gets its permission on the next provision.
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
                $existing = Role::where('name', $roleEnum->value)->first();

                if ($roleEnum === TenantRole::Owner) {
                    // Always everything, even when the role already exists —
                    // this is the lock and the recovery path in one.
                    $role = $existing ?? Role::findOrCreate($roleEnum->value, 'web');
                    $role->syncPermissions(Permissions::all());

                    continue;
                }

                if ($existing !== null) {
                    // A workspace's edits are its own. Never overwritten.
                    continue;
                }

                Role::findOrCreate($roleEnum->value, 'web')
                    ->syncPermissions($roleEnum->defaultPermissions());
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Provision every existing tenant — used by migrations and safe to run
     * whenever the vocabulary grows (owner picks up the new permissions,
     * custom role edits are left alone).
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
