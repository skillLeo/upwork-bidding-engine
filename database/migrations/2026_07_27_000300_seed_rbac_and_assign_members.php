<?php

use App\Authorization\RoleProvisioner;
use App\Authorization\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Provisions the four roles for every existing tenant and assigns the
 * current users into them, mapping from the legacy users.role column:
 *
 *   the tenant's owner_user_id          -> owner
 *   any other legacy 'admin'            -> admin
 *   legacy 'bidder'                     -> bidder
 *
 * The legacy users.role column is left in place — nothing authorizes off it
 * after this phase, but dropping it is a separate, riskier change for no
 * benefit, and it stays useful as a cheap "primary role" display.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Permission caching keyed on the previous (empty) state would hide
        // the rows this migration writes.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $provisioner = app(RoleProvisioner::class);

        Tenancy::asPlatform(function () use ($provisioner) {
            Tenant::query()->get()->each(function (Tenant $tenant) use ($provisioner) {
                $provisioner->provision($tenant);

                Tenancy::runAs($tenant, function () use ($tenant) {
                    $memberIds = $tenant->users()->pluck('users.id');

                    foreach (User::whereIn('id', $memberIds)->get() as $user) {
                        $role = $this->roleFor($tenant, $user);
                        // syncRoles (not assign) so re-running the migration is
                        // idempotent rather than stacking duplicate assignments.
                        $user->syncRoles([$role->value]);
                    }
                });
            });
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function roleFor(Tenant $tenant, User $user): TenantRole
    {
        if ($tenant->owner_user_id === $user->id) {
            return TenantRole::Owner;
        }

        return $user->role?->value === 'admin' ? TenantRole::Admin : TenantRole::Bidder;
    }

    public function down(): void
    {
        // Role/permission rows are torn down by the Spatie tables' own
        // migration on rollback; nothing to reverse here beyond that.
    }
};
