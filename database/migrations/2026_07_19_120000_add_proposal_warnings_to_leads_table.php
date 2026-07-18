<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unresolved quality-gate violations for the stored proposal, shown as a
 * red badge on the lead page. NULL/empty = the shipped text passed every
 * check. Activity-log-only warnings proved invisible in practice — the
 * operator reads proposals on the lead page, so the warning lives there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->json('proposal_warnings')->nullable()->after('proposal_text');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('proposal_warnings');
        });
    }
};
