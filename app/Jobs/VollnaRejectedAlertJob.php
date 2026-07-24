<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsForTenant;
use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Services\OpsAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Fires when a Vollna webhook delivery is rejected (bad/missing secret) —
 * the exact failure that once went unnoticed for a day. Queued so the 401
 * response to Vollna stays instant; the queue cron drains within a minute.
 * Once per incident: the flag below stays set until an authenticated
 * delivery arrives and the webhook controller clears it.
 */
class VollnaRejectedAlertJob implements ShouldQueue
{
    use RunsForTenant;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ALERTED_CACHE_KEY = 'vollna:rejected_alerted';

    // Best effort by design — a failed alert send shouldn't retry-storm a
    // queue whose whole purpose is signalling that something is broken.
    public int $tries = 1;

    public function __construct(public string $reason, public ?string $ip)
    {
        $this->captureTenant();
    }

    public function handle(OpsAlertService $alerts): void
    {
        $this->forTenant(fn () => $this->run($alerts));
    }

    protected function run(OpsAlertService $alerts): void
    {
        if (Cache::has(self::ALERTED_CACHE_KEY)) {
            return;
        }

        $message = sprintf(
            "🔴 SkillLeo: a Vollna webhook delivery was REJECTED — reason: %s (from IP %s).\n\n"
            .'Every delivery will keep failing until the Bearer token in Vollna matches the webhook secret in Settings.',
            $this->reason,
            $this->ip ?? 'unknown',
        );

        if (! $alerts->send($message)) {
            return;
        }

        Cache::forever(self::ALERTED_CACHE_KEY, now()->toIso8601String());

        ActivityLog::record(ActivityType::VollnaRejectedAlert, meta: [
            'reason' => $this->reason,
            'ip' => $this->ip,
        ]);
    }
}
