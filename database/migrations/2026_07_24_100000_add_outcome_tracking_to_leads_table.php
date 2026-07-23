<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The measurements the system exists to optimise but has never captured:
 * how fast a proposal actually goes out after a job posts, whether the
 * client ever opened it, and what really happened afterward - including
 * distinguishing "closed_no_hire" (nobody won this job, not a personal
 * loss) from a real loss, which the reply-rate math has been conflating.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('status');
            $table->timestamp('viewed_at')->nullable()->after('submitted_at');
            $table->string('outcome', 32)->nullable()->after('viewed_at');
            $table->timestamp('outcome_at')->nullable()->after('outcome');

            $table->index('submitted_at');
            $table->index('outcome');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['submitted_at']);
            $table->dropIndex(['outcome']);
            $table->dropColumn(['submitted_at', 'viewed_at', 'outcome', 'outcome_at']);
        });
    }
};
