<?php

namespace App\Http\Middleware;

use App\Enums\ActivityType;
use App\Jobs\VollnaRejectedAlertJob;
use App\Models\ActivityLog;
use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifyVollnaSecret
{
    public function __construct(protected SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $expected = $this->settings->vollnaWebhookSecret();

        // Vollna's own webhook UI only offers Bearer Token / Basic Auth / None —
        // there's no custom-header option — so Bearer is the primary path.
        // X-Vollna-Secret and ?secret= stay as fallbacks for manual/local testing.
        $provided = $request->bearerToken()
            ?? $request->header('X-Vollna-Secret')
            ?? $request->query('secret');

        if (! $expected || ! $provided || ! hash_equals($expected, (string) $provided)) {
            $reason = ! $expected ? 'no_secret_configured' : 'secret_mismatch';

            ActivityLog::record(ActivityType::WebhookRejected, meta: [
                'source' => 'vollna',
                'ip' => $request->ip(),
                'reason' => $reason,
            ]);

            // Alert the operator once per incident. Cache::add is the
            // dispatch-side guard (so a burst of rejections can't flood
            // the queue with alert jobs); the job re-checks its own
            // once-per-incident flag before actually sending.
            if (! Cache::has(VollnaRejectedAlertJob::ALERTED_CACHE_KEY)
                && Cache::add('vollna:rejected_alert_dispatched', 1, 300)) {
                VollnaRejectedAlertJob::dispatch($reason, $request->ip());
            }

            return response()->json(['message' => 'Invalid or missing webhook secret.'], 401);
        }

        return $next($request);
    }
}
