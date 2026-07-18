<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The five rubric pillars (client quality, competition, stack fit,
 * budget, post quality) behind the overall score, so a 6 is explainable
 * on the lead page instead of a bare number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->json('sub_scores')->nullable()->after('score_reason');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('sub_scores');
        });
    }
};
