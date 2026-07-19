<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification outcome, made visible on the lead itself:
 * - notification_skipped_reason: scored + written but deliberately not
 *   sent to WhatsApp (e.g. already stale at scoring time). The lead stays
 *   on the dashboard at its real score; the phone stays quiet.
 * - notify_error: the WhatsApp alert genuinely failed after all retries —
 *   shown as a red badge so a dead tunnel is never silent again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('notification_skipped_reason')->nullable()->after('proposal_warnings');
            $table->string('notify_error', 500)->nullable()->after('notification_skipped_reason');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['notification_skipped_reason', 'notify_error']);
        });
    }
};
