<?php

use App\Authorization\Permissions;
use App\Authorization\RoleProvisioner;
use App\Models\Tenant;
use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * One-time expansion of the original 17 coarse permissions into the
 * ultra-granular vocabulary, so NOBODY LOSES A CAPABILITY they had before
 * this release. Each old grant maps onto the granular permissions that
 * cover the same surface; the legacy rows are then removed.
 *
 * Idempotent and safe on a fresh install (no roles yet -> nothing to map;
 * the seeder creates roles straight on the new defaults).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Creates every granular permission row and re-syncs owner to ALL.
        // Non-owner roles are left untouched here (create-once semantics) —
        // the mapping below is what upgrades them.
        app(RoleProvisioner::class)->provisionAll();

        $secretKeys = array_keys(array_filter(SettingsService::SCHEMA, fn (array $m) => $m['secret']));
        $nonSecretKeys = array_keys(array_filter(SettingsService::SCHEMA, fn (array $m) => ! $m['secret']));

        $map = [
            'settings.edit_rules' => array_map(Permissions::forSettingKey(...), $nonSecretKeys),
            'settings.edit_secrets' => [
                ...array_map(Permissions::forSettingKey(...), $secretKeys),
                Permissions::SETTINGS_TEST_CONNECTIONS,
                Permissions::AI_USAGE_VIEW,
            ],
            'proposals.edit' => [Permissions::PROPOSALS_EDIT_MANUAL, Permissions::PROPOSALS_AI_EDIT],
            'proposals.rewrite' => [Permissions::PROPOSALS_AI_REWRITE],
            'leads.update_status' => [
                Permissions::LEADS_SET_OUTCOME, Permissions::LEADS_SET_CLIENT_VIEW,
                Permissions::LEADS_FAVORITE, Permissions::LEADS_BULK,
            ],
            'leads.view' => [
                Permissions::LEADS_AI_SEARCH, Permissions::PROPOSALS_VERSIONS_VIEW,
                Permissions::NOTIFICATIONS_VIEW,
            ],
            'leads.delete' => [Permissions::LEADS_SYNC_VOLLNA],
            'clients.message' => [Permissions::CLIENTS_AI_DRAFT_REPLY],
            'settings.view' => [Permissions::DIAGNOSTICS_VIEW],
            // Whoever could assign roles becomes the initial holder of the
            // new permission-editing power (owner has it regardless).
            'members.assign_role' => [Permissions::PERMISSIONS_EDIT],
        ];

        Tenancy::asPlatform(function () use ($map) {
            // withTrashed(): see the identical comment in
            // 2026_07_27_000300_seed_rbac_and_assign_members — a later
            // migration adds Tenant's soft-delete column, and Eloquent
            // models in migrations always run the CURRENT class file.
            Tenant::withTrashed()->get()->each(function (Tenant $tenant) use ($map) {
                Tenancy::runAs($tenant, function () use ($map) {
                    foreach (['admin', 'bidder', 'viewer'] as $roleName) {
                        $role = Role::where('name', $roleName)->first();

                        if ($role === null) {
                            continue;
                        }

                        $held = $role->permissions->pluck('name')->all();
                        $additions = [];

                        foreach ($map as $coarse => $granular) {
                            if (in_array($coarse, $held, true)) {
                                $additions = [...$additions, ...$granular];
                            }
                        }

                        if ($additions !== []) {
                            $role->givePermissionTo(array_unique($additions));
                        }
                    }
                });
            });
        });

        // The coarse rows are no longer part of the vocabulary; removing them
        // cascades their role/user links away so the matrix never shows a
        // permission nothing checks anymore.
        DB::table('permissions')->whereIn('name', [
            'settings.edit_rules', 'settings.edit_secrets',
            'proposals.edit', 'proposals.rewrite',
        ])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The expansion is additive and the coarse rows carry no data worth
        // resurrecting; recreating them without their old grants would be
        // misleading. No structural change to reverse.
    }
};
