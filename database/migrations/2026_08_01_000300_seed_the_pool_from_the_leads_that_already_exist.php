<?php

use App\Models\Lead;
use App\Models\LeadSourceItem;
use App\Tenancy\Tenancy;
use Illuminate\Database\Migrations\Migration;

/**
 * Give every lead that already exists a source item, and link the two.
 *
 * Without this the pool starts empty, which would mean a workspace created
 * tomorrow backfills from nothing and the platform console can't tell which
 * workspaces are looking at the same job. Every lead in the database today
 * came through Vollna, so each one is evidence of a pool item that should
 * have existed all along — this reconstructs them after the fact.
 *
 * Leads sharing an external_id across workspaces collapse onto ONE item, in
 * id order: the earliest row wins the facts. In practice there are no such
 * pairs yet (that is precisely what the global unique prevented), but the
 * unique is dropped in the migration immediately before this one, so writing
 * it as if collisions exist keeps this re-runnable afterwards.
 *
 * fanned_out_at is stamped on every item created here. These jobs have
 * already reached the workspaces that were entitled to them, and leaving it
 * null would invite the distributor to re-offer months of history to every
 * workspace at once the first time it runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        // TENANCY: rebuilding the shared pool from every workspace's rows is
        // inherently cross-tenant — that is the migration.
        Tenancy::asPlatform(function () {
            $created = 0;
            $linked = 0;

            Lead::query()
                ->whereNull('source_item_id')
                ->orderBy('id')
                ->chunkById(200, function ($leads) use (&$created, &$linked) {
                    foreach ($leads as $lead) {
                        $facts = [];

                        foreach (LeadSourceItem::PROJECTED_COLUMNS as $column) {
                            $facts[$column] = $lead->getAttribute($column);
                        }

                        $item = LeadSourceItem::query()
                            ->where('external_id', $lead->external_id)
                            ->first();

                        if ($item === null) {
                            $item = LeadSourceItem::create([
                                ...$facts,
                                'source' => 'vollna',
                                'fanned_out_at' => now(),
                            ]);
                            $created++;
                        }

                        // saveQuietly: Lead::booted() stamps submitted_at on
                        // any update that touches status. Nothing here does,
                        // but a backfill must not be one refactor away from
                        // rewriting a workspace's "when did this go out"
                        // history.
                        $lead->forceFill(['source_item_id' => $item->id])->saveQuietly();
                        $linked++;
                    }
                });

            echo "  Pool items created: {$created}. Leads linked: {$linked}.".PHP_EOL;
        });
    }

    public function down(): void
    {
        Tenancy::asPlatform(function () {
            Lead::query()->whereNotNull('source_item_id')->update(['source_item_id' => null]);
            LeadSourceItem::query()->delete();
        });
    }
};
