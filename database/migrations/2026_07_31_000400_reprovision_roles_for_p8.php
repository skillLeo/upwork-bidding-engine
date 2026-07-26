<?php

use App\Authorization\RoleProvisioner;
use Illuminate\Database\Migrations\Migration;

/**
 * Re-provision every workspace after the P8 role change.
 *
 * Two things need it. The 'admin' role was just deleted, so each tenant must
 * be re-checked against the three-role vocabulary; and the owner role is
 * re-synced to the full permission set on every provision, which is how new
 * permissions reach it. Same pattern and same reason as 2026_07_29_000700 and
 * 2026_07_30_000200.
 *
 * Runs AFTER the admin-role deletion (see the timestamps) so it cannot
 * recreate what that migration just removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(RoleProvisioner::class)->provisionAll();
    }

    public function down(): void
    {
        // Additive only (role rows and owner permission grants).
    }
};
