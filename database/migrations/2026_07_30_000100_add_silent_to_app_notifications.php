<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A notification row is written for EVERY alerted lead, including ones the
 * dispatcher deliberately silenced — a stale lead, or alerts muted in
 * Settings — so nothing is ever invisible on the dashboard.
 *
 * `silent` carries that single decision (made once, in
 * NotificationDispatcher) out to the client, so the in-app toast can respect
 * it without re-deriving the freshness/mute rules in JavaScript. Without it
 * the toast would pop for leads the server had already decided must not
 * interrupt anyone.
 *
 * Defaults to false so every pre-existing row keeps behaving exactly as it
 * does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->boolean('silent')->default(false)->after('level');
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropColumn('silent');
        });
    }
};
