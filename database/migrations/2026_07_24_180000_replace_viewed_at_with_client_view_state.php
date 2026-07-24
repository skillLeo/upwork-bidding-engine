<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two corrections to the outcome tracking shipped in
 * 2026_07_24_100000_add_outcome_tracking_to_leads_table:
 *
 * 1. viewed_at was a two-state timestamp (set = viewed, null = everything
 *    else), which conflated "the client did not open it" with "I have not
 *    recorded this yet". A half-filled field therefore read as a total
 *    title failure. client_view replaces it with three explicit states and
 *    a genuinely distinct NULL for "not recorded".
 *
 * 2. outcome carried 'replied' and 'hired_me', which duplicate status
 *    Replied and status Won. Those rows are cleared here — the information
 *    is not lost, status already holds it — and the two values are gone
 *    from the enum, so the fields can no longer contradict each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('client_view', 16)->nullable()->after('submitted_at');
            $table->index('client_view');
        });

        // A recorded viewed_at meant exactly one thing: the client opened it.
        DB::table('leads')->whereNotNull('viewed_at')->update(['client_view' => 'viewed']);

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('viewed_at');
        });

        // Drop the now-removed enum values. status already records both, so
        // nothing is lost; leaving them would make the cast throw on read.
        DB::table('leads')->whereIn('outcome', ['replied', 'hired_me'])
            ->update(['outcome' => null, 'outcome_at' => null]);
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('viewed_at')->nullable()->after('submitted_at');
        });

        // Only 'viewed' and 'shortlisted' had a viewed_at equivalent. The
        // timestamp is unrecoverable (it recorded when the state was set,
        // which client_view never stored), so use updated_at as the closest
        // honest stand-in rather than inventing now().
        DB::table('leads')->whereIn('client_view', ['viewed', 'shortlisted'])
            ->update(['viewed_at' => DB::raw('updated_at')]);

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['client_view']);
            $table->dropColumn('client_view');
        });
    }
};
