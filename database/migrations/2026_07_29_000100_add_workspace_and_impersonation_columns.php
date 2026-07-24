<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5: workspace soft-delete (the Settings > Workspace "Delete workspace"
 * action) and impersonation columns on the token table (the platform
 * console's read-only "become this user" session).
 *
 * Soft-delete rather than a hard cascade: Tenant::query() excludes a deleted
 * row app-wide the instant this lands (subdomain resolution, login, the
 * scheduler's tenantsToRun()) — the workspace simply stops functioning —
 * without a single-shot irreversible destruction of every related table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('impersonator_id')->nullable()->after('tenant_id')
                ->constrained('users')->nullOnDelete();
            $table->string('impersonation_reason', 500)->nullable()->after('impersonator_id');
            $table->timestamp('impersonation_expires_at')->nullable()->after('impersonation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('impersonator_id');
            $table->dropColumn(['impersonation_reason', 'impersonation_expires_at']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
