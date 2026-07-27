<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Services\OpsAlertService;
use App\Services\SettingsService;
use App\Services\VollnaProjectImporter;
use App\Tenancy\Tenancy;
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
    public const FAIL_ALERT_KEY = 'vollna:poll_failure_alerted';

    protected $signature = 'vollna:poll-api {--quiet-ok : only print when something was imported}';

    protected $description = 'Poll the Vollna filter API for new projects and distribute them to every workspace';

    /**
     * ONE poll for the whole platform.
     *
     * This used to loop every workspace and poll Vollna once per workspace,
     * because the Vollna credentials were tenant settings back when there
     * was one tenant. After the credentials moved to the platform layer,
     * every workspace was polling the SAME filter with the SAME token — one
     * subscription being hit N times a minute for identical results.
     * Measured live: 1,441 requests/day with one workspace, 3,944/day the
     * day three customer workspaces were created, and rising with every
     * customer onboarded.
     *
     * It could not have worked in any case: each workspace then tried to
     * insert the same job into `leads`, which carried a GLOBAL unique on
     * external_id, so every workspace after the first failed at the index.
     * The failure was invisible — the per-project catch below counted it as
     * "skipped" and the command still returned SUCCESS.
     *
     * Now: poll once, put the job in the shared pool, and let LeadFanOut
     * hand it to every eligible workspace. The billing gate that used to
     * live in the tenant loop moved to LeadFanOut::eligibleWorkspaces(),
     * which is where the AI spend is actually triggered.
     */
    public function handle(SettingsService $settings, VollnaProjectImporter $importer, OpsAlertService $alerts): int
    {
        $platform = Tenant::platformWorkspace();

        if ($platform === null) {
            $this->error('No internal-plan workspace — intake has no operator to run as.');

            return self::FAILURE;
        }

        return Tenancy::runAs($platform, fn () => $this->poll($settings, $importer, $alerts));
    }

    protected function poll(SettingsService $settings, VollnaProjectImporter $importer, OpsAlertService $alerts): int
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
        $delivered = 0;

        foreach ($projects as $project) {
            try {
                // Scoring is always QUEUED from here. This command runs
                // INSIDE the scheduler process (proc_open is disabled on
                // this host), so it must return in seconds — and one job now
                // means one scoring run PER WORKSPACE, which no scheduler
                // tick could absorb inline. The scheduler's queue closure
                // drains them within the next minute.
                $result = $importer->ingest($importer->normalizeApiProject($project));
            } catch (\Throwable $e) {
                report($e);
                $skipped++;

                continue;
            }

            $delivered += (int) ($result['delivered'] ?? 0);

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
                'delivered_to_workspaces' => $delivered,
            ]);
        }

        if ($accepted > 0 || ! $this->option('quiet-ok')) {
            $this->info(sprintf(
                'Polled %d projects: %d new, %d duplicate, %d skipped — %d workspace deliveries.',
                count($projects),
                $accepted,
                $duplicate,
                $skipped,
                $delivered,
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
