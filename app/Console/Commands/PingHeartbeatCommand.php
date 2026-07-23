<?php

namespace App\Console\Commands;

use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Fires an outbound ping to an external dead-man's-switch monitor
 * (Healthchecks.io / Cronitor / UptimeRobot free tier) once per scheduler
 * tick — the ONE alert in this system that can fire from OUTSIDE the box.
 * Everything else that would notice the scheduler dying (the queue drain,
 * health:check, vollna:check-silence) rides the same single Hostinger cron
 * entry, so if that cron dies, every alarm that depends on it dies with it.
 *
 * Scheduled directly after the cron-heartbeat entry in routes/console.php,
 * so this only runs once the tick has actually reached that point - a tick
 * that errored earlier never gets here, and never pings, which is what
 * makes an external monitor meaningful instead of trivially green forever.
 */
class PingHeartbeatCommand extends Command
{
    public const LAST_ATTEMPT_KEY = 'heartbeat:last_attempt_at';

    public const LAST_RESULT_KEY = 'heartbeat:last_result';

    protected $signature = 'ops:heartbeat';

    protected $description = 'Ping the configured external heartbeat monitor (no-op if unconfigured)';

    public function handle(SettingsService $settings): int
    {
        $url = trim((string) $settings->get('heartbeat_ping_url', ''));

        if ($url === '') {
            return self::SUCCESS;
        }

        Cache::forever(self::LAST_ATTEMPT_KEY, now()->toIso8601String());

        // A slow or dead monitor must NEVER delay or break the scheduler tick
        // itself - short timeouts, and every failure mode (network, DNS, non-2xx,
        // even an unexpected throw) is swallowed here, not propagated.
        try {
            $response = Http::timeout(3)->connectTimeout(2)->get($url);

            if ($response->successful()) {
                Cache::forever(self::LAST_RESULT_KEY, 'ok');

                return self::SUCCESS;
            }

            Cache::forever(self::LAST_RESULT_KEY, 'failed: HTTP '.$response->status());
        } catch (\Throwable $e) {
            Cache::forever(self::LAST_RESULT_KEY, 'failed: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
