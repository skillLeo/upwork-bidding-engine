<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Unauthenticated liveness probe, for an EXTERNAL uptime monitor to poll.
 *
 * This is the OTHER half of the dead-man's switch, and the two halves detect
 * genuinely different failures:
 *
 *   ops:heartbeat  (push) — the scheduler dials OUT every minute. Silence
 *                           means the hPanel cron itself died. Nothing
 *                           inside this app can ever detect that, because
 *                           every alarm it owns rides that same cron.
 *   /health/ping   (pull) — a monitor dials IN. A timeout, a 5xx, or ok:false
 *                           means the web app / PHP-FPM / MySQL is down,
 *                           which the push half CANNOT report: a dead app
 *                           still has a live cron happily pinging "all fine".
 *
 * Point one monitor at each and the two failures page separately.
 *
 * The body is deliberately thin. This route has no auth, so it exposes only
 * the three numbers a monitor needs to make a green/red decision and nothing
 * that describes the pipeline, the settings, or any lead.
 */
class HealthPingController extends Controller
{
    /**
     * A tick older than this means the cron has stopped. The scheduler
     * stamps cron:last_tick every minute, so 180s absorbs one wholly missed
     * minute plus clock skew before calling it dead.
     */
    private const STALE_TICK_SECONDS = 180;

    public function __invoke(): JsonResponse
    {
        // Both reads can hit the database (CACHE_STORE is file on production
        // today, but that is config, not a guarantee), so they share one
        // guard. A dead DB must surface as a deliberate 503 rather than as
        // an unhandled exception: 503 is the honest answer for a monitor,
        // and it keeps the response shape fixed on a route with no auth
        // instead of depending on APP_DEBUG to decide how much of the
        // stack trace — DB host, port, schema — gets rendered.
        try {
            $secondsAgo = $this->secondsSinceLastTick();
            // TENANCY: jobs/failed_jobs are Laravel infrastructure tables, not
            // tenant-owned — there is one shared queue on this host by design.
            $queueDepth = DB::table('jobs')->count();
            $failedJobs = DB::table('failed_jobs')->count();
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'cron_last_tick_seconds_ago' => null,
                'queue_depth' => null,
                'failed_jobs' => null,
            ], 503);
        }

        // Otherwise always HTTP 200, in both the fresh and stale states. The
        // monitor is configured to read `ok` out of the body; if a dead cron
        // also returned a non-2xx, the pull monitor would fire for a failure
        // the push monitor already owns and every cron outage would page
        // twice. 503 is reserved for "this app is broken", which is the one
        // thing only the pull monitor can see.
        return response()->json([
            'ok' => $secondsAgo !== null && $secondsAgo <= self::STALE_TICK_SECONDS,
            'cron_last_tick_seconds_ago' => $secondsAgo,
            'queue_depth' => $queueDepth,
            'failed_jobs' => $failedJobs,
        ]);
    }

    /**
     * Null when the scheduler has never ticked (fresh deploy, or a cache
     * that was flushed) — reported as null rather than 0 so a monitor can
     * tell "never ran" apart from "ran just now".
     */
    private function secondsSinceLastTick(): ?int
    {
        $tick = Cache::get('cron:last_tick');

        if (! is_string($tick) || $tick === '') {
            return null;
        }

        try {
            // Plain epoch arithmetic rather than diffInSeconds(), whose sign
            // and return type differ between Carbon majors.
            return max(0, now()->getTimestamp() - Carbon::parse($tick)->getTimestamp());
        } catch (\Throwable) {
            // An unparseable stamp is a corrupt cache value, not a dead app —
            // report "never ticked" rather than escalating to the 503 above.
            return null;
        }
    }
}
