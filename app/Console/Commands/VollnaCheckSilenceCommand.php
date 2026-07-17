<?php

namespace App\Console\Commands;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Services\OpsAlertService;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Dead-man's switch for the Vollna webhook. Vollna has gone silently dark
 * twice (stale secret, then an API-token outage) and the only signal was
 * someone eventually noticing leads had stopped — this closes that gap.
 * Runs hourly from the scheduler; alerts once per incident, and the
 * incident resets the moment an authenticated delivery arrives (the
 * webhook controller clears the flag and re-stamps the timestamp).
 */
class VollnaCheckSilenceCommand extends Command
{
    public const ALERTED_CACHE_KEY = 'vollna:silence_alerted';

    protected $signature = 'vollna:check-silence';

    protected $description = 'Alert (once per incident) when no Vollna webhook delivery has arrived within the configured window';

    public function handle(SettingsService $settings, OpsAlertService $alerts): int
    {
        $threshold = max(1, (int) $settings->get('vollna_silence_alert_hours', 6));
        $lastRaw = $settings->get('vollna_last_webhook_at');

        $last = null;

        if ($lastRaw) {
            try {
                $last = Carbon::parse($lastRaw);
            } catch (\Throwable) {
                // Corrupt timestamp — fall through to re-initialize below.
            }
        }

        if (! $last) {
            // First run (or unreadable stamp): start the clock now instead
            // of alerting about a period nothing was measuring.
            $settings->set('vollna_last_webhook_at', now()->toIso8601String());
            $this->info('Initialized vollna_last_webhook_at; no check performed.');

            return self::SUCCESS;
        }

        $silentHours = $last->diffInHours(now());

        if ($silentHours < $threshold) {
            $this->info(sprintf('Webhook alive: last delivery %.1fh ago (threshold %dh).', $silentHours, $threshold));

            return self::SUCCESS;
        }

        if (Cache::has(self::ALERTED_CACHE_KEY)) {
            $this->info('Silence ongoing but already alerted for this incident.');

            return self::SUCCESS;
        }

        $message = sprintf(
            "⚠️ SkillLeo: no Vollna webhook deliveries for %.0f hours (alert threshold %dh).\n\n"
            ."The webhook may be off — check Vollna's dashboard: Notifications → webhook toggle, the URL, and the Bearer token.\n"
            .'Catch up on missed leads any time with Settings → Vollna → Sync now.',
            $silentHours,
            $threshold,
        );

        // Only mark the incident as alerted if an alert actually went out
        // somewhere — if both channels failed (e.g. the Mac is off AND mail
        // is down), the next hourly run tries again.
        if (! $alerts->send($message)) {
            $this->warn('Silence detected but no alert channel succeeded; will retry next run.');

            return self::FAILURE;
        }

        Cache::forever(self::ALERTED_CACHE_KEY, now()->toIso8601String());

        ActivityLog::record(ActivityType::VollnaSilenceAlert, meta: [
            'silent_hours' => round($silentHours, 1),
            'threshold_hours' => $threshold,
        ]);

        $this->info(sprintf('Alert sent: %.1fh of webhook silence.', $silentHours));

        return self::SUCCESS;
    }
}
