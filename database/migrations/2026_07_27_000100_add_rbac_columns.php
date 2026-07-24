<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The non-Spatie half of the access-control schema.
 *
 * Tenant roles (owner/admin/bidder/viewer) live in Spatie's tables, scoped
 * by tenant. Everything here is the part Spatie does not own:
 *
 *  - platform_role on users: a SEPARATE namespace from the tenant roles, on
 *    purpose. A customer-editable permission system that could grant
 *    platform access is a privilege-escalation hole, so platform staff are
 *    identified by a column no tenant UI can ever write.
 *
 *  - submitted_by_user_id on leads: which PERSON first sent this proposal.
 *    This is the attribution that tells an agency which bidder converts —
 *    the single most valuable screen for that buyer, and impossible to
 *    reconstruct after the fact if not captured now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable: the overwhelming majority of users are tenant users
            // with no platform access at all. Only Hassam's own account, and
            // any future staff, carry a value here.
            $table->string('platform_role', 32)->nullable()->after('role');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('submitted_by_user_id')->nullable()->after('submitted_at')
                ->constrained('users')->nullOnDelete();
            $table->index(['tenant_id', 'submitted_by_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'submitted_by_user_id']);
            $table->dropForeign(['submitted_by_user_id']);
            $table->dropColumn('submitted_by_user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('platform_role');
        });
    }
};
