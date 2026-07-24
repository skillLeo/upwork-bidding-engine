<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Push subscriptions were tenant-wide only (no owner) — WebPushService sent
 * to every device in the tenant unconditionally. P5's per-user notification
 * preferences need to know WHOSE device a subscription is to honour "which
 * push" / quiet hours. Nullable and backward compatible on purpose: existing
 * rows (subscribed before this shipped) keep user_id = null and keep
 * receiving everything unconditionally — no regression for a device that
 * can't be attributed to a preference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('tenant_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
