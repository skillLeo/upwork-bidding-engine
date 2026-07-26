<?php

use App\Authorization\RoleProvisioner;
use Illuminate\Database\Migrations\Migration;

/**
 * The WhatsApp Cloud API adds nine new SettingsService::SCHEMA keys, and
 * setting.{key} permissions are DERIVED from that schema — so the permissions
 * exist the moment the code ships, but no role has been granted them yet.
 *
 * RoleProvisioner re-syncs the OWNER role to the full permission set on every
 * provision() call and leaves other roles alone (deny-by-default for new
 * permissions is deliberate — see RoleProvisioner's docblock). Without this,
 * the owner would be unable to save their own WhatsApp credentials until
 * something happened to trigger a re-provision. Same pattern, same reason as
 * 2026_07_29_000700.
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
