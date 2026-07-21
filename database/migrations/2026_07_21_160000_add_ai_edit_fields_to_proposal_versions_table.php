<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-assisted edit metadata: the natural-language instruction the operator
 * gave, and the character span it targeted (both null for a whole-proposal
 * instructed rewrite, and for non-AI version types). Token/cost stay in the
 * ai_calls table, referenced by nothing here on purpose - one source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_versions', function (Blueprint $table) {
            $table->text('edit_instruction')->nullable()->after('model');
            $table->unsignedInteger('selection_start')->nullable()->after('edit_instruction');
            $table->unsignedInteger('selection_end')->nullable()->after('selection_start');
        });
    }

    public function down(): void
    {
        Schema::table('proposal_versions', function (Blueprint $table) {
            $table->dropColumn(['edit_instruction', 'selection_start', 'selection_end']);
        });
    }
};
