<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Services\SettingsService;
use App\Services\VollnaProjectImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * Manual, button-triggered mirror of a Vollna filter's *current* results —
 * unlike vollna:backfill (add-only, meant for a one-time catch-up), this
 * also removes leads that no longer match, so a narrowed Vollna filter
 * narrows the Laravel dashboard too. Never runs on a schedule; only ever
 * dispatched by a user pressing "Sync now".
 */
class VollnaSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    protected const MAX_PAGES = 20;

    public function handle(VollnaProjectImporter $importer, SettingsService $settings): void
    {
        $token = $settings->vollnaApiToken();
        $filterId = $settings->vollnaFilterId();

        if (! $token || ! $filterId) {
            $this->recordResult($settings, [
                'success' => false,
                'message' => 'Missing Vollna API token or filter ID in Settings.',
            ]);

            return;
        }

        $seenExternalIds = [];
        $accepted = 0;
        $duplicate = 0;
        $cursor = null;
        $reachedEnd = false;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = Http::withHeaders(['X-API-TOKEN' => $token])
                ->get("https://api.vollna.com/v1/filters/{$filterId}/projects", array_filter([
                    'limit' => 100,
                    'next_cursor' => $cursor,
                ]));

            if ($response->failed()) {
                // Never touch the removal step on a failed fetch — an auth
                // hiccup returning nothing must not read as "the filter is
                // now empty" and wipe every lead.
                $this->recordResult($settings, [
                    'success' => false,
                    'message' => "Vollna API request failed on page {$page}: HTTP {$response->status()}.",
                ]);

                return;
            }

            $body = $response->json();
            $projects = $body['data'] ?? [];

            foreach ($projects as $project) {
                // Queue the scoring: a mirror sync can add dozens of leads,
                // and inline 60-90s scoring runs are what made this job
                // blow past its timeout and die mid-run.
                $result = $importer->importProject($importer->normalizeApiProject($project), scoreInline: false);

                if (in_array($result['status'], ['accepted', 'duplicate'], true)) {
                    $lead = Lead::find($result['lead_id']);

                    if ($lead) {
                        $seenExternalIds[] = $lead->external_id;
                    }

                    $result['status'] === 'accepted' ? $accepted++ : $duplicate++;
                }
            }

            $isLast = (bool) ($body['pagination']['is_last'] ?? true);
            $cursor = $body['pagination']['next_cursor'] ?? null;

            if ($isLast || ! $cursor) {
                $reachedEnd = true;

                break;
            }

            // Same per-minute rate limit vollna:backfill was built against.
            sleep(13);
        }

        if (! $reachedEnd) {
            // Hit MAX_PAGES without reaching the end of the filter's
            // results - we don't have the full picture, so removing
            // "unseen" leads would delete ones simply not fetched yet.
            $this->recordResult($settings, [
                'success' => false,
                'message' => "Stopped after ".self::MAX_PAGES." pages without reaching the end — sync incomplete, no leads removed.",
                'accepted' => $accepted,
                'duplicate' => $duplicate,
            ]);

            return;
        }

        $removed = Lead::query()
            ->where('external_id', 'like', 'vollna_%')
            ->whereNotIn('external_id', $seenExternalIds)
            ->get();

        $removedCount = $removed->count();

        if ($removedCount > 0) {
            $backupPath = storage_path('app/vollna_sync_removed_'.now()->format('Ymd_His').'.json');
            file_put_contents($backupPath, $removed->toJson());

            Lead::whereIn('id', $removed->pluck('id'))->delete();
        }

        $this->recordResult($settings, [
            'success' => true,
            'message' => "Synced: {$accepted} added, {$removedCount} removed, ".count($seenExternalIds)." total matching.",
            'added' => $accepted,
            'removed' => $removedCount,
            'total_matching' => count($seenExternalIds),
        ]);

        ActivityLog::record('vollna_sync_completed', meta: [
            'added' => $accepted,
            'duplicate' => $duplicate,
            'removed' => $removedCount,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function recordResult(SettingsService $settings, array $result): void
    {
        $settings->set('vollna_last_sync', array_merge($result, ['finished_at' => now()->toIso8601String()]));
    }
}
