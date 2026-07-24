<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns an anonymous row in personal_access_tokens into something a person
 * can recognise on a "Devices and sessions" screen and revoke with
 * confidence. Without a device label and a rough location, "sign out this
 * device" is a guess.
 *
 * tenant_id is nullable and NOT covered by the tenancy global scope: this is
 * a framework auth table, and a user may belong to several workspaces. It
 * records which workspace the token was issued from, for the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('last_used_at'); // 45 = IPv6
            $table->text('user_agent')->nullable()->after('ip_address');
            $table->string('device_label')->nullable()->after('user_agent');
            $table->string('approx_location')->nullable()->after('device_label');
            $table->foreignId('tenant_id')->nullable()->after('approx_location')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn(['ip_address', 'user_agent', 'device_label', 'approx_location', 'tenant_id']);
        });
    }
};
