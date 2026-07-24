<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The answer to "who signed in, from where, when" — which this app currently
 * cannot answer at all.
 *
 * APPEND-ONLY BY CONTRACT. There is no update or delete path anywhere in the
 * app, and AuthEvent enforces that in code (see the model): an audit log a
 * compromised admin can quietly edit is not an audit log.
 *
 * Deliberately separate from activity_logs. That table is product telemetry
 * (leads scored, proposals sent) and is 91k rows and pruneable; this is a
 * security record with different retention and different access rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_events', function (Blueprint $table) {
            $table->id();
            // Nullable: a failed login has no user, by definition.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            // Stored even when no user matched, so credential-stuffing against
            // non-existent accounts is still visible.
            $table->string('email_attempted')->nullable();
            // A string, not a DB enum: adding a value to a MySQL enum is a
            // table rebuild, and this app already had to widen leads.status
            // for exactly that reason.
            $table->string('event', 32);
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('approx_location')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
            // Powers the lockout/abuse queries without scanning the table.
            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_events');
    }
};
