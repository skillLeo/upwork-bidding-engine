<?php

namespace App\Console\Commands;

use App\Services\VollnaProjectImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * One-time import of jobs a Vollna filter already matched before its
 * webhook was attached (the webhook only delivers new matches going
 * forward, it never backfills). Not scheduled - run manually once per
 * filter when needed: php artisan vollna:backfill {token} {filterId}
 */
class VollnaBackfillCommand extends Command
{
    protected $signature = 'vollna:backfill {token : Vollna API token (Developers > API Tokens)} {filter : The numeric filter ID, e.g. 40694 from the filter URL} {--pages=10 : Max pages to fetch, 100 projects each} {--hours= : Only import projects published within this many hours; omit for no limit}';

    protected $description = 'One-time import of projects an existing Vollna filter already matched, via the REST API.';

    public function handle(VollnaProjectImporter $importer): int
    {
        $token = (string) $this->argument('token');
        $filterId = (string) $this->argument('filter');
        $maxPages = (int) $this->option('pages');
        $hours = $this->option('hours') !== null ? (int) $this->option('hours') : null;
        $cutoff = $hours !== null ? now()->subHours($hours) : null;

        $accepted = 0;
        $duplicate = 0;
        $skipped = 0;
        $tooOld = 0;
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
            $reachedCutoff = false;

            foreach ($projects as $project) {
                // Results come back newest-published-first, so the moment
                // one project is older than the cutoff, every project after
                // it (this page and all following pages) is too - safe to
                // stop the whole backfill right here instead of importing
                // (and paying for) hundreds of dead older briefs.
                if ($cutoff !== null) {
                    $publishedAt = $project['publishedAt'] ?? null;

                    if ($publishedAt && Carbon::parse($publishedAt)->lt($cutoff)) {
                        $tooOld++;
                        $reachedCutoff = true;

                        break;
                    }
                }

                $result = $importer->importProject($importer->normalizeApiProject($project));

                match ($result['status']) {
                    'accepted' => $accepted++,
                    'duplicate' => $duplicate++,
                    default => $skipped++,
                };
            }

            $this->line("Page {$page}: ".count($projects)." projects fetched.");

            if ($reachedCutoff) {
                $this->info("Reached the {$hours}h cutoff — stopping early.");

                break;
            }

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

        $this->info("Done. Accepted: {$accepted}, duplicate: {$duplicate}, skipped: {$skipped}, too old: {$tooOld}.");

        return self::SUCCESS;
    }
}
