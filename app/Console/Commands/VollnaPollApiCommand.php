<?php

namespace App\Console\Commands;

use App\Console\Concerns\RunsForTenants;
use App\Models\ActivityLog;
use App\Services\OpsAlertService;
use App\Services\SettingsService;
use App\Services\VollnaProjectImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The live intake door, replacing the webhook Vollna moved behind their
 * Agency plan. Polls the filter's REST endpoint on a short schedule and
 * hands every project to VollnaProjectImporter — the same service the
 * webhook and the backfill use — so dedupe, hard filters, scoring,
 * proposal writing and the WhatsApp alert are all unchanged downstream.
 *
 * Deliberately ADDITIVE ONLY. VollnaSyncJob mirrors the filter and deletes
 * leads it doesn't see, which is correct for the manual "Sync now" button
 * but catastrophic on a schedule: this endpoint returns only the newest
 * page, so a mirroring poll would delete the entire back catalogue every
 * few minutes. This command never deletes anything.
 *
 * No pagination params: Vollna rejects `limit` with 400 "Invalid
 * pagination parameters", which is what silently broke both existing API
 * callers. The bare call returns the newest projects, which is exactly
 * what a frequent poll wants.
 */
class VollnaPollApiCommand extends Command
{
    use RunsForTenants;

    public const FAIL_ALERT_KEY = 'vollna:poll_failure_alerted';

    protected $signature = 'vollna:poll-api {--quiet-ok : only print when something was imported}
        {--tenant= : run for this tenant only (default: every operable tenant)}';

    protected $description = 'Poll the Vollna filter API for new projects and run them through the intake pipeline';

    public function handle(SettingsService $settings, VollnaProjectImporter $importer, OpsAlertService $alerts): int
    {
        return $this->forEachTenant(fn () => $this->runForTenant($settings, $importer, $alerts));
    }

    protected function runForTenant(SettingsService $settings, VollnaProjectImporter $importer, OpsAlertService $alerts): int
    {
        $token = $settings->vollnaApiToken();
        $filterId = $settings->vollnaFilterId();

        if (! $token || ! $filterId) {
            $this->error('Vollna API token or filter ID is not set — add them in Settings → Vollna.');

            return self::FAILURE;
        }

        try {
            $response = Http::withHeaders(['X-API-TOKEN' => $token])
                ->timeout(30)
                ->get("https://api.vollna.com/v1/filters/{$filterId}/projects");
        } catch (\Throwable $e) {
            return $this->reportFailure($alerts, 'request threw: '.$e->getMessage());
        }

        if ($response->failed()) {
            return $this->reportFailure($alerts, 'HTTP '.$response->status().' — '.mb_substr($response->body(), 0, 200));
        }

        $projects = $response->json()['data'] ?? [];

        // Debug-only capture, not a feature: several fields in mapPayload()
        // (connects_required among them) were mapped from guessed key names
        // with no real payload ever available to confirm against (see that
        // method's own comment). Cheap, self-overwriting, never grows -
        // remove once the real field names are confirmed and fixed.
        if ($projects !== []) {
            Cache::forever('vollna:last_raw_project_sample', mb_substr(json_encode($projects[0]), 0, 5000));
        }

        // A healthy authenticated response proves the intake path is alive
        // even when every project is a duplicate — that's what the
        // dead-man's switch watches.
        $settings->set('vollna_last_intake_at', now()->toIso8601String());

        // Recovery is silent, matching the other alerting paths.
        if (Cache::has(self::FAIL_ALERT_KEY)) {
            Cache::forget(self::FAIL_ALERT_KEY);
            ActivityLog::record('vollna_poll_recovered');
        }

        $accepted = 0;
        $duplicate = 0;
        $skipped = 0;

        foreach ($projects as $project) {
            try {
                // scoreInline false: this command runs INSIDE the scheduler
                // process (proc_open is disabled on this host), so it must
                // return in seconds — scoring is queued and drained by the
                // scheduler's queue closure within the next minute.
                $result = $importer->importProject($importer->normalizeApiProject($project), scoreInline: false);
            } catch (\Throwable $e) {
                report($e);
                $skipped++;

                continue;
            }

            match ($result['status'] ?? 'skipped') {
                'accepted' => $accepted++,
                'duplicate' => $duplicate++,
                default => $skipped++,
            };
        }

        if ($accepted > 0) {
            ActivityLog::record('vollna_poll_imported', meta: [
                'accepted' => $accepted,
                'duplicate' => $duplicate,
                'skipped' => $skipped,
            ]);
        }

        if ($accepted > 0 || ! $this->option('quiet-ok')) {
            $this->info(sprintf(
                'Polled %d projects: %d new, %d duplicate, %d skipped.',
                count($projects),
                $accepted,
                $duplicate,
                $skipped,
            ));
        }

        return self::SUCCESS;
    }

    protected function reportFailure(OpsAlertService $alerts, string $reason): int
    {
        $this->error('Vollna poll failed: '.$reason);

        ActivityLog::record('vollna_poll_failed', meta: ['reason' => $reason]);

        // Once per incident — a poll running every 2 minutes must never
        // turn an outage into hundreds of WhatsApp messages.
        if (Cache::add(self::FAIL_ALERT_KEY, now()->toIso8601String(), now()->addHours(6))) {
            $alerts->send(
                "⚠️ SkillLeo: Vollna lead intake is failing.\n\n"
                .$reason
                ."\n\nNew leads have stopped arriving. Check the API token in Settings → Vollna."
            );
        }

        return self::FAILURE;
    }
}
