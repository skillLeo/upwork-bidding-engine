<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only history of every proposal a lead has ever had. A proposal
     * itself lives on leads.proposal_text (one current text per lead); this
     * table snapshots that text on every change - a fresh AI write, a rewrite,
     * or a manual edit - so the operator can see what changed and (later) the
     * "final sent" text can be exported as training data.
     *
     * There is deliberately NO proposals table: proposals are a column on the
     * lead, so every row here references leads.id and version_number is
     * sequential PER LEAD. Rows are never updated once written; a correction is
     * a new row, which is what makes the sent snapshot an honest record of what
     * the client actually received.
     */
    public function up(): void
    {
        Schema::create('proposal_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            // initial_draft | rewrite | manual_edit
            $table->string('edit_type', 32);
            $table->mediumText('body');
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedInteger('char_count')->default(0);
            // Result of ProposalLinter::check() at the moment this version was
            // written - the "safeguards run on every version" guarantee lives
            // here, not just on the current proposal_warnings column.
            $table->unsignedInteger('linter_violation_count')->default(0);
            $table->json('linter_violations')->nullable();
            $table->json('linter_rules_fixed')->nullable();
            // Writer model for AI-produced versions; null for a manual edit.
            $table->string('model', 64)->nullable();
            // The exact text sent to the client is frozen here so a later edit
            // to proposal_text never rewrites history.
            $table->boolean('is_sent')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['lead_id', 'version_number']);
            $table->index('is_sent');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_versions');
    }
};
