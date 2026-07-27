<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one line that made this product single-workspace.
 *
 * `leads.external_id` has been globally UNIQUE since the first migration,
 * written when there was one workspace and the idea of a second had not come
 * up. Once tenancy shipped, that index quietly became the hard ceiling on
 * the whole product: an Upwork job could exist as exactly one row in the
 * entire database, so whichever workspace received it first owned it
 * forever and every other workspace was refused at the index.
 *
 * It failed silently, which is why it survived. The intake poller loops
 * every workspace, and VollnaPollApiCommand catches each per-project
 * exception, counts it "skipped" and returns SUCCESS — so a workspace that
 * could never receive a single lead reported perfect health while sitting at
 * zero. Proven on the live database: the founding workspace held 148 leads,
 * the three customer workspaces held 0 each, and a hand-rolled insert of an
 * existing external_id under a different tenant_id returns
 * "Duplicate entry ... for key 'leads.leads_external_id_unique'".
 *
 * Uniqueness is still wanted, just one level down: a job may appear once
 * PER WORKSPACE. Global dedupe moves to lead_source_items, where a single
 * shared pool is the actual intent.
 *
 * The plain (tenant_id, external_id) index added by the tenancy migration is
 * dropped in the same breath — the new unique has identical leading columns
 * and makes it dead weight on every write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique('leads_external_id_unique');
            $table->dropIndex('leads_tenant_id_external_id_index');
            $table->unique(['tenant_id', 'external_id']);

            // Which pool item this row was projected from. Nullable because
            // leads that predate the pool are linked by the backfill that
            // runs next, and because a lead created by hand (tests, the
            // Agent API) legitimately has no source item behind it.
            //
            // nullOnDelete, not cascade: pruning the pool must never delete
            // a workspace's scored lead, its proposal or its outcome
            // history. Losing the provenance link is acceptable; losing the
            // work is not.
            $table->foreignId('source_item_id')
                ->nullable()
                ->after('external_id')
                ->constrained('lead_source_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_item_id');
            $table->dropUnique(['tenant_id', 'external_id']);
            $table->index(['tenant_id', 'external_id']);
            $table->unique('external_id');
        });
    }
};
