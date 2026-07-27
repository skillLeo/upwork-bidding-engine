<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The intake pool: one row per job the platform has ever seen.
 *
 * THE PROBLEM THIS SOLVES. `leads` carried two unrelated kinds of column in
 * one table — facts about the job (title, brief, budget, the client) and one
 * workspace's opinion of it (score, status, favourite, proposal). Because
 * `leads` is tenant-scoped, the facts were scoped too, so a job could belong
 * to exactly one workspace. `leads.external_id` was even globally UNIQUE,
 * which made that a database guarantee rather than an oversight: whichever
 * workspace received a job first owned it forever and every other workspace
 * was locked out at the index. Three customer workspaces sat at zero leads
 * while the founding workspace held 148.
 *
 * So the facts move here, once, unscoped — and `leads` keeps only the
 * opinions. One Vollna subscription feeds the pool; every workspace is then
 * handed its own copy to score, filter and bid on independently.
 *
 * DELIBERATELY NOT TENANT-SCOPED. This table has no tenant_id at all, rather
 * than a nullable one: a source item is never owned by anybody. Making that
 * structural means BelongsToTenant can't be added to the model by reflex
 * later, which would silently empty the pool for every workspace at once.
 *
 * The global unique on external_id is correct HERE — a job really is one job
 * platform-wide — which is exactly why it was wrong on `leads`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_source_items', function (Blueprint $table) {
            $table->id();

            // Where the unique belongs. Global dedupe is a property of the
            // pool, not of any workspace.
            $table->string('external_id')->unique();

            // 'vollna' today; the IMAP intake and any future feed land in the
            // same pool, so a workspace never has to care where a job came
            // from.
            $table->string('source', 32)->default('vollna');

            // Column types mirror `leads` exactly so a projection is a
            // straight copy with no lossy conversion in either direction.
            $table->string('title');
            $table->longText('full_brief');
            $table->json('skills')->nullable();
            $table->string('url')->nullable();
            $table->string('budget')->nullable();
            $table->decimal('budget_min', 10, 2)->nullable();
            $table->decimal('budget_max', 10, 2)->nullable();
            $table->string('budget_type')->nullable();
            $table->string('client_country')->nullable();
            $table->string('client_spend')->nullable();
            $table->decimal('client_spend_amount', 12, 2)->nullable();
            $table->string('client_hire_rate')->nullable();
            $table->decimal('client_hire_rate_pct', 5, 1)->nullable();
            $table->decimal('client_rating', 2, 1)->nullable();
            $table->unsignedInteger('client_reviews')->nullable();
            $table->boolean('payment_verified')->default(false);
            $table->unsignedInteger('proposal_count')->default(0);
            $table->unsignedInteger('connects_required')->nullable();
            $table->timestamp('posted_at')->nullable();

            // Stamped once the item has been offered to every workspace that
            // existed at the time. A workspace created afterwards backfills
            // from this table by date, so this is a progress marker for the
            // fan-out and not a "done forever" flag.
            $table->timestamp('fanned_out_at')->nullable();

            $table->timestamps();

            // The two ways this table is ever read: newest-first for the
            // fan-out backfill, and "what hasn't been distributed yet".
            $table->index('posted_at');
            $table->index('fanned_out_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_source_items');
    }
};
