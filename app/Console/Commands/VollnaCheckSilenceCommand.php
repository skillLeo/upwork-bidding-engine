<?php

namespace App\Console\Commands;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Services\OpsAlertService;
use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Dead-man's switch for Vollna intake (webhook OR the API poller — whichever
 * is the live door on the current plan). Vollna has gone silently dark
 * multiple times (a stale secret, an API-token outage, an insufficient-
 * credits outage) and the only signal was someone eventually noticing leads
 * had stopped — this closes that gap.
 *
 * Runs hourly from the scheduler; alerts once per incident. The incident
 * clears here, on THIS command's own next healthy run — not only via
 * VollnaWebhookController's Cache::forget on a fresh webhook delivery. That
 * webhook-only clear was the sole path for a long time and is a real bug on
 * this account: Vollna's webhooks need their paid Agency plan, which this
 * account doesn't have, so a real webhook delivery structurally never
 * happens here. Under polling-only intake that made the flag permanently
 * one-way once set — the FIRST-ever silence alert would fire, and every
 * subsequent outage, forever after, would alert silently never again.
 * Confirmed live 2026-07-24: exactly this happened - one alert on 7/23
 * 04:00 for a still-ongoing outage that started 7/22 17:13, with zero
 * further alerts despite the outage continuing well past that point.
 */
class VollnaCheckSilenceCommand extends Command
{
    public const ALERTED_CACHE_KEY = 'vollna:silence_alerted';

    protected $signature = 'vollna:check-silence';

    protected $description = 'Alert (once per incident) when Vollna intake has gone quiet past the configured window';

    /**
     * Watches the ONE intake door, not one per workspace.
     *
     * This used to loop every workspace, from when each workspace polled
     * Vollna for itself. Intake is now a single platform-level poll feeding
     * a shared pool, so only the platform's own workspace ever stamps
     * vollna_last_intake_at — and looping would have every customer
     * workspace report an outage that does not exist, one false alarm per
     * customer, growing with every one onboarded.
     */
    public function handle(SettingsService $settings, OpsAlertService $alerts): int
    {
        $platform = Tenant::platformWorkspace();

        if ($platform === null) {
            $this->error('No workspace to check intake for.');

            return self::FAILURE;
        }

        return Tenancy::runAs($platform, fn () => $this->check($settings, $alerts));
    }

    protected function check(SettingsService $settings, OpsAlertService $alerts): int
    {
        $threshold = max(1, (int) $settings->get('vollna_silence_alert_hours', 6));

        // Watches intake from ANY door — the API poller (the live path now
        // that webhooks are Agency-only) or a webhook delivery, whichever
        // is more recent — so restoring either one clears the alarm.
        $lastRaw = collect([
            $settings->get('vollna_last_intake_at'),
            $settings->get('vollna_last_webhook_at'),
        ])->filter()->max();

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
            // Self-clear on recovery, the same as VollnaPollApiCommand's own
            // failure flag does - this is the ONLY reliable recovery signal
            // under polling-only intake, since the webhook-delivery clear in
            // VollnaWebhookController never fires on a plan with no webhooks.
            if (Cache::has(self::ALERTED_CACHE_KEY)) {
                Cache::forget(self::ALERTED_CACHE_KEY);
                ActivityLog::record(ActivityType::VollnaSilenceRecovered, meta: [
                    'silent_hours_at_recovery' => round($silentHours, 1),
                ]);
                $this->info('Intake recovered — silence alert flag cleared.');
            }

            $this->info(sprintf('Intake alive: last delivery %.1fh ago (threshold %dh).', $silentHours, $threshold));

            return self::SUCCESS;
        }

        if (Cache::has(self::ALERTED_CACHE_KEY)) {
            $this->info('Silence ongoing but already alerted for this incident.');

            return self::SUCCESS;
        }

        // Active hours: 09:00–23:00 Pakistan time. A quiet night isn't
        // worth waking anyone for — the flag stays unset, so the silence
        // CLOCK keeps running overnight and a night outage alerts on the
        // first check after 09:00 with the full elapsed hours.
        $hour = now('Asia/Karachi')->hour;

        if ($hour < 9 || $hour >= 23) {
            $this->info('Silence detected but outside active hours (09:00–23:00 PKT); deferring alert.');

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
