<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The original enum column predates the needs_review status, so every
 * attempt to mark a lead needs_review crashed on MySQL with "data
 * truncated" — meaning the scoring-failure safety net never actually
 * worked in production (SQLite tests don't enforce enums, so CI never
 * saw it). A plain string keeps the index, accepts every LeadStatus
 * case, and ends the schema-must-mirror-the-PHP-enum foot-gun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('status', 20)->default('new')->change();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->enum('status', ['new', 'scoring', 'ready', 'sent', 'replied', 'won', 'archived'])
                ->default('new')->change();
        });
    }
};
