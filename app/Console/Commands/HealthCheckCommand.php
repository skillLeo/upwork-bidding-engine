<?php

namespace App\Console\Commands;

use App\Services\OpenClawService;
use App\Services\OpsAlertService;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Every-5-minutes watchdog for the two services the pipeline can't work
 * without: OpenClaw (scoring + all WhatsApp sends) and the WhatsApp Web
 * session behind it. One alert per outage, cleared silently on recovery —
 * never a message every 5 minutes. Vollna silence has its own hourly
 * check (vollna:check-silence) with different timing semantics.
 *
 * When OpenClaw itself is down, the WhatsApp alert obviously can't route
 * through it — OpsAlertService falls back to admin email, which is the
 * whole reason that fallback exists.
 */
class HealthCheckCommand extends Command
{
    public const OPENCLAW_DOWN_KEY = 'health:openclaw_down_alerted';

    public const WHATSAPP_DOWN_KEY = 'health:whatsapp_down_alerted';

    protected $signature = 'health:check';

    protected $description = 'Check OpenClaw and WhatsApp health; alert once per outage';

    public function handle(SettingsService $settings, OpenClawService $openClaw, OpsAlertService $alerts): int
    {
        if (! $settings->openClawUrl()) {
            $this->info('OpenClaw not configured; nothing to check.');

            return self::SUCCESS;
        }

        $reachable = $openClaw->isReachable();

        if (! $reachable) {
            if (! Cache::has(self::OPENCLAW_DOWN_KEY) && $alerts->send(
                "🔴 SkillLeo: OpenClaw is UNREACHABLE.\n\n"
                .'New leads will queue instead of scoring in real time, and WhatsApp alerts are paused. '
                .'Check that the Mac is awake, the bridge is running, and the ngrok URL in Settings still matches the tunnel.'
            )) {
                Cache::forever(self::OPENCLAW_DOWN_KEY, now()->toIso8601String());
            }

            $this->warn('OpenClaw unreachable.');

            return self::SUCCESS;
        }

        Cache::forget(self::OPENCLAW_DOWN_KEY);

        $whatsapp = $openClaw->whatsappStatus();

        if (! $whatsapp['connected']) {
            if (! Cache::has(self::WHATSAPP_DOWN_KEY) && $alerts->send(
                "🔴 SkillLeo: WhatsApp is DISCONNECTED on OpenClaw (state: {$whatsapp['health']}).\n\n"
                .'Lead alerts cannot be delivered until the WhatsApp Web session is re-linked in OpenClaw.'
            )) {
                Cache::forever(self::WHATSAPP_DOWN_KEY, now()->toIso8601String());
            }

            $this->warn('WhatsApp disconnected.');

            return self::SUCCESS;
        }

        Cache::forget(self::WHATSAPP_DOWN_KEY);

        $this->info('OpenClaw reachable, WhatsApp connected.');

        return self::SUCCESS;
    }
}
