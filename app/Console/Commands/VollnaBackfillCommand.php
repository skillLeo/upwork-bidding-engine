<?php

namespace App\Console\Commands;

use App\Services\VollnaProjectImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * One-time import of jobs a Vollna filter already matched before its
 * webhook was attached (the webhook only delivers new matches going
 * forward, it never backfills). Not scheduled - run manually once per
 * filter when needed: php artisan vollna:backfill {token} {filterId}
 */
class VollnaBackfillCommand extends Command
{
    protected $signature = 'vollna:backfill {token : Vollna API token (Developers > API Tokens)} {filter : The numeric filter ID, e.g. 40694 from the filter URL} {--pages=10 : Max pages to fetch, 100 projects each}';

    protected $description = 'One-time import of projects an existing Vollna filter already matched, via the REST API.';

    public function handle(VollnaProjectImporter $importer): int
    {
        $token = (string) $this->argument('token');
        $filterId = (string) $this->argument('filter');
        $maxPages = (int) $this->option('pages');

        $accepted = 0;
        $duplicate = 0;
        $skipped = 0;
        $cursor = null;

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = Http::withHeaders(['X-API-TOKEN' => $token])
                ->get("https://api.vollna.com/v1/filters/{$filterId}/projects", array_filter([
                    'limit' => 100,
                    'next_cursor' => $cursor,
                ]));

            if ($response->failed()) {
                $this->error("Vollna API request failed on page {$page}: HTTP {$response->status()} - {$response->body()}");

                return self::FAILURE;
            }

            $body = $response->json();
            $projects = $body['data'] ?? [];

            foreach ($projects as $project) {
                $result = $importer->importProject($this->normalizeApiProject($project));

                match ($result['status']) {
                    'accepted' => $accepted++,
                    'duplicate' => $duplicate++,
                    default => $skipped++,
                };
            }

            $this->line("Page {$page}: ".count($projects)." projects fetched.");

            $isLast = (bool) ($body['pagination']['is_last'] ?? true);
            $cursor = $body['pagination']['next_cursor'] ?? null;

            if ($isLast || ! $cursor) {
                break;
            }

            // Vollna's API rate limit held steady at exactly 5 requests
            // before a 429 in testing, regardless of a 2s pause between
            // pages - this looks like a per-minute cap, not a burst guard.
            // Space requests ~13s apart so 5 fit inside a 60s window.
            sleep(13);
        }

        $this->info("Done. Accepted: {$accepted}, duplicate: {$duplicate}, skipped: {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * The REST API returns a differently-shaped project than the webhook
     * batch does (camelCase, budget as {type, amount}, publishedAt) -
     * translate it into the webhook's shape so VollnaProjectImporter's
     * already-tested mapping logic handles both without duplicating it.
     *
     * @param  array<string, mixed>  $project
     * @return array<string, mixed>
     */
    protected function normalizeApiProject(array $project): array
    {
        $client = $project['clientDetails'] ?? [];
        $budget = $project['budget'] ?? [];
        $budgetType = strtolower((string) ($budget['type'] ?? ''));

        return [
            'title' => $project['title'] ?? null,
            'description' => $project['description'] ?? null,
            'url' => $project['url'] ?? null,
            'published' => $project['publishedAt'] ?? null,
            'budget' => $budget['amount'] ?? null,
            'budget_type' => str_contains($budgetType, 'hour') ? 'hourly' : 'fixed',
            'client_details' => [
                'country' => $client['country'] ?? null,
                'total_spent' => $client['totalSpent'] ?? null,
                'hire_rate' => $client['hireRate'] ?? null,
                'payment_method_verified' => $client['paymentMethodVerified'] ?? null,
                'rating' => $client['rating'] ?? null,
                'reviews' => $client['reviews'] ?? null,
            ],
        ];
    }
}
