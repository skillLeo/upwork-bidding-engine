<?php

use App\Authorization\TenantRole;
use App\Models\Tenant;
use App\Tenancy\Tenancy;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Give every existing non-owner role the setting keys its own defaults say it
 * should have, but which did not exist when the role was created.
 *
 * THE GAP. RoleProvisioner seeds a role's defaults once, at creation, and
 * never touches it again — deliberately, so a workspace's edits to the Roles
 * matrix survive deploys. The cost of that rule is invisible until a release
 * adds a settings key: the permission is created, the owner picks it up (the
 * owner is re-synced every time), and every other role silently cannot reach
 * it. Forever. Nothing surfaces the gap, because the Roles matrix shows what
 * IS granted, not what the role was supposed to have.
 *
 * It surfaced the hard way. The founding workspace's bidder role was missing
 * 18 permissions, three of them (notify_score_min, quiet_hours_start,
 * quiet_hours_end) rendered on the Bidding Rules form — so a bidder pressing
 * Save got "You do not have permission to change [notify_score_min]" and the
 * ENTIRE form failed, including the fields they were allowed to edit.
 *
 * STRICTLY ADDITIVE. Only permissions in the role's own code defaults, only
 * where currently absent. Nothing is ever removed, so a workspace that
 * deliberately narrowed a role keeps every one of those choices.
 *
 * The honest caveat: a workspace that deliberately REVOKED one of these keys
 * gets it back. There is no way to tell "revoked on purpose" from "never
 * granted because it didn't exist yet" — the database records the same
 * absence for both. Restoring the documented default is the better failure:
 * it is visible in the Roles matrix and one click to undo, whereas the
 * alternative leaves a form that cannot be saved and no clue why.
 */
return new class extends Migration
{
    public function up(): void
    {
        $teamKey = config('permission.column_names.team_foreign_key', 'tenant_id');

        // TENANCY: role rows are per workspace; repairing them all is the job.
        Tenancy::asPlatform(function () use ($teamKey) {
            $tenants = Tenant::withTrashed()->orderBy('id')->get();
            $totalGranted = 0;

            foreach ($tenants as $tenant) {
                foreach (TenantRole::cases() as $roleEnum) {
                    if ($roleEnum === TenantRole::Owner) {
                        continue; // Re-synced to everything on every provision already.
                    }

                    $role = Role::where('name', $roleEnum->value)->where($teamKey, $tenant->id)->first();

                    if ($role === null) {
                        continue;
                    }

                    $granted = $role->permissions->pluck('name')->all();
                    $missing = array_values(array_diff($roleEnum->defaultPermissions(), $granted));

                    // Only permissions that actually exist as rows — a default
                    // naming something never created would otherwise throw.
                    $missing = array_values(array_filter(
                        $missing,
                        fn (string $name) => Permission::where('name', $name)->where('guard_name', 'web')->exists(),
                    ));

                    if ($missing === []) {
                        continue;
                    }

                    $role->givePermissionTo($missing);
                    $totalGranted += count($missing);

                    echo sprintf(
                        '  %s / %s: granted %d missing default permission(s) — %s%s',
                        $tenant->slug,
                        $roleEnum->value,
                        count($missing),
                        implode(', ', array_slice($missing, 0, 6)).(count($missing) > 6 ? ', …' : ''),
                        PHP_EOL,
                    );
                }
            }

            echo PHP_EOL."  Total permissions granted: {$totalGranted}.".PHP_EOL;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Not reversed: revoking these would restore the exact state that
        // made a settings form impossible to save.
    }
};
