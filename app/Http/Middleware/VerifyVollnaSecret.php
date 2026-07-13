<?php

namespace App\Http\Middleware;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
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
            ActivityLog::record(ActivityType::WebhookRejected, meta: [
                'source' => 'vollna',
                'ip' => $request->ip(),
                'reason' => ! $expected ? 'no_secret_configured' : 'secret_mismatch',
            ]);

            return response()->json(['message' => 'Invalid or missing webhook secret.'], 401);
        }

        return $next($request);
    }
}
