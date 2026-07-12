<?php

namespace App\Http\Controllers;

use App\Http\Requests\Webhooks\VollnaWebhookRequest;
use App\Services\VollnaProjectImporter;
use Illuminate\Http\JsonResponse;

class VollnaWebhookController extends Controller
{
    public function __construct(protected VollnaProjectImporter $importer) {}

    public function __invoke(VollnaWebhookRequest $request): JsonResponse
    {
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
