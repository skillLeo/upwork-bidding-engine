<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user notification read state.
 *
 * app_notifications had ONE shared read_at for the whole workspace: one
 * person opening the bell marked it read for everybody, so nobody else ever
 * saw their own count. This table gives every user their own read state,
 * with a composite primary key so a user can mark a notification read
 * exactly once.
 *
 * The old shared read_at column is left in place, unused. Dropping it would
 * be a second, riskier migration for no benefit; the code simply stops
 * reading it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notification_reads', function (Blueprint $table) {
            $table->foreignId('app_notification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');

            $table->primary(['app_notification_id', 'user_id']);
        });

        // Migrate the existing shared state: every notification currently
        // marked read is marked read for every existing user, so nobody is
        // suddenly shown a backlog of "unread" history on first load.
        $readIds = DB::table('app_notifications')->whereNotNull('read_at')
            ->pluck('read_at', 'id');
        $userIds = DB::table('users')->pluck('id');

        if ($readIds->isNotEmpty() && $userIds->isNotEmpty()) {
            $rows = [];
            foreach ($readIds as $notificationId => $readAt) {
                foreach ($userIds as $userId) {
                    $rows[] = [
                        'app_notification_id' => $notificationId,
                        'user_id' => $userId,
                        'read_at' => $readAt,
                    ];
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('app_notification_reads')->insertOrIgnore($chunk);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notification_reads');
    }
};
