<?php

namespace App\Http\Controllers;

use App\Console\Commands\VollnaCheckSilenceCommand;
use App\Http\Requests\Webhooks\VollnaWebhookRequest;
use App\Jobs\VollnaRejectedAlertJob;
use App\Services\SettingsService;
use App\Services\VollnaProjectImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class VollnaWebhookController extends Controller
{
    public function __construct(
        protected VollnaProjectImporter $importer,
        protected SettingsService $settings,
    ) {}

    public function __invoke(VollnaWebhookRequest $request): JsonResponse
    {
        // An authenticated delivery — even one carrying only duplicates —
        // is proof the webhook is alive: stamp it for the dead-man's
        // switch and close any open silence/rejection incident so the
        // NEXT outage alerts again.
        $this->settings->set('vollna_last_webhook_at', now()->toIso8601String());
        Cache::forget(VollnaCheckSilenceCommand::ALERTED_CACHE_KEY);
        Cache::forget(VollnaRejectedAlertJob::ALERTED_CACHE_KEY);

        // Vollna's "new job" webhook delivers a batch: one POST can carry
        // several matching projects at once, not one job per request.
        $projects = (array) $request->input('projects', []);

        $results = array_map(fn (array $project) => $this->importer->importProject($project), $projects);

        $accepted = count(array_filter($results, fn ($r) => $r['status'] === 'accepted'));
        $duplicate = count(array_filter($results, fn ($r) => $r['status'] === 'duplicate'));
        $skipped = count(array_filter($results, fn ($r) => $r['status'] === 'skipped'));

        return response()->json([
            'data' => [
                'total' => count($results),
                'accepted' => $accepted,
                'duplicate' => $duplicate,
                'skipped' => $skipped,
                'results' => $results,
            ],
        ], $accepted > 0 ? 201 : 200);
    }
}
