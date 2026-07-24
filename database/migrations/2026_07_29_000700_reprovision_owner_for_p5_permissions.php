<?php

use App\Authorization\RoleProvisioner;
use Illuminate\Database\Migrations\Migration;

/**
 * P5 added new permissions (workspace.manage, plus one setting.{key} per new
 * SCHEMA entry — quotas, security, platform, oauth). RoleProvisioner only
 * re-syncs the OWNER role's grants on every provision() call; it never
 * touches an already-created workspace's other roles (deny-by-default is
 * deliberate — see RoleProvisioner's own docblock). Nothing in this deploy
 * otherwise calls provision() again for existing tenants, so without this,
 * every already-provisioned workspace's owner would be missing the new
 * permissions until someone happened to trigger a re-provision — exactly
 * the gap the owner-is-the-recovery-path design exists to avoid. Mirrors
 * 2026_07_28_000200_expand_coarse_permissions_to_granular's identical
 * pattern for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(RoleProvisioner::class)->provisionAll();
    }

    public function down(): void
    {
        // Additive only (new permission grants on the owner role) — no
        // meaningful reverse operation.
    }
};
