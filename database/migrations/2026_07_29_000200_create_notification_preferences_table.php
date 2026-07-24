<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user, per-workspace notification preferences (P5 Profile extension).
 * Tenant-owned (BelongsToTenant) even though it's keyed by user: the SAME
 * person can belong to several workspaces and reasonably want different
 * quiet hours for each. tenant_id leads the unique index and every query, so
 * this passes TenancyGuardTest like every other tenant-owned table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('email_on_new_lead')->default(true);
            $table->boolean('email_on_reminder')->default(true);
            $table->boolean('push_on_new_lead')->default(true);
            $table->boolean('push_on_reminder')->default(true);
            // Hour-of-day (app timezone), inclusive start / exclusive end,
            // wrapping past midnight (e.g. 22 -> 7). Null = no quiet hours.
            $table->unsignedTinyInteger('quiet_hours_start')->nullable();
            $table->unsignedTinyInteger('quiet_hours_end')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'user_id'], 'notification_preferences_tenant_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
