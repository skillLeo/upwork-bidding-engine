<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenant_id on every tenant-owned table, each with a COMPOSITE index leading
 * with tenant_id and paired with that table's existing hot column. Every
 * query in the app is about to gain a tenant predicate, so an index on
 * tenant_id alone would leave the Leads page doing a filesort.
 *
 * Nullable here. The backfill runs in the next migration and tightens the
 * columns to NOT NULL afterwards, so the two steps stay independently
 * reversible instead of being one migration that can half-fail.
 *
 * TWO TABLES HERE THAT THE SPEC'S LIST OMITTED: saved_filters and templates.
 * Both are account-wide configuration today, so unscoped they would let one
 * tenant read another's saved lead filters and proposal templates. The spec
 * also names the activity table "activity_log"; it is actually
 * "activity_logs".
 */
return new class extends Migration
{
    /**
     * table => existing hot column(s) to pair tenant_id with. An empty list
     * means there is no hot column worth pairing, so tenant_id is indexed
     * alone.
     *
     * settings and deleted_lead_external_ids are absent on purpose: both need
     * a UNIQUE index rather than a plain one, and are handled below.
     * invitations already ships with tenant_id from the previous migration.
     *
     * @var array<string, array<int, string>>
     */
    private const COMPOSITES = [
        'leads' => ['status', 'posted_at', 'external_id'],
        'proposal_versions' => ['lead_id'],
        'clients' => ['stage'],
        'messages' => ['client_id'],
        'activity_logs' => ['created_at'],
        'ai_calls' => ['created_at'],
        'app_notifications' => ['created_at'],
        'push_subscriptions' => [],
        'saved_filters' => [],
        'templates' => [],
    ];

    public function up(): void
    {
        foreach (self::COMPOSITES as $table => $hotColumns) {
            Schema::table($table, function (Blueprint $blueprint) use ($hotColumns) {
                $blueprint->foreignId('tenant_id')->nullable()->after('id')
                    ->constrained()->cascadeOnDelete();

                if ($hotColumns === []) {
                    $blueprint->index('tenant_id');

                    return;
                }

                foreach ($hotColumns as $hot) {
                    $blueprint->index(['tenant_id', $hot]);
                }
            });
        }

        // settings: `key` was globally unique. It now has to be unique PER
        // TENANT, with tenant_id NULL meaning "platform default" — the row a
        // tenant falls back to when it has no override of its own. No extra
        // plain index: the unique already leads with tenant_id.
        Schema::table('settings', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
            $table->dropUnique(['key']);
            $table->unique(['tenant_id', 'key']);
        });

        // This is the one that bites quietly. An Upwork job ID is global; if
        // one tenant prunes a lead and the tombstone stays unscoped, that job
        // becomes permanently invisible to every other tenant — they simply
        // never see it arrive, and nothing anywhere reports why.
        Schema::table('deleted_lead_external_ids', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
            $table->dropUnique(['external_id']);
            $table->unique(['tenant_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('deleted_lead_external_ids', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'external_id']);
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
            $table->unique('external_id');
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'key']);
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
            $table->unique('key');
        });

        foreach (self::COMPOSITES as $table => $hotColumns) {
            Schema::table($table, function (Blueprint $blueprint) use ($hotColumns) {
                if ($hotColumns === []) {
                    $blueprint->dropIndex(['tenant_id']);
                } else {
                    foreach ($hotColumns as $hot) {
                        $blueprint->dropIndex(['tenant_id', $hot]);
                    }
                }

                $blueprint->dropForeign(['tenant_id']);
                $blueprint->dropColumn('tenant_id');
            });
        }
    }
};
