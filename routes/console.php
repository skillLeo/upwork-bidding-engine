<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Every task below runs IN-PROCESS via Artisan::call, never as a
| Schedule::command. This host (Hostinger shared) disables proc_open, so
| Schedule::command — which spawns a subprocess — throws a LogicException
| on every run and silently executes NOTHING; only closures work. That is
| exactly how the poller, queue drain, and health checks were all dead
| for hours on 2026-07-19 while the heartbeat closure kept ticking.
|
| Consequences respected here:
| 1. Tasks run sequentially inside one schedule:run, so each must be
|    FAST — the poller queues scoring instead of running it inline.
| 2. Every closure gets withoutOverlapping with a SHORT expiry so a
|    killed cron self-heals in minutes, never the 24h default.
*/

Schedule::call(fn () => Artisan::call('leads:follow-up-reminders'))
    ->daily()
    ->name('follow-up-reminders')
    ->withoutOverlapping(30);

// The live intake door. Vollna moved webhooks behind their Agency plan,
// so new leads arrive by polling the filter API — every minute, the
// honest latency ceiling on the Freelancer plan (worst case ~60s from
// Vollna publishing to the lead existing here). Vollna publishes no
// documented rate limit; at 1 request/min the failure alert (once per
// incident) is the tripwire if they ever object with 429s.
// Additive only — never deletes (that's the manual Sync now button).
Schedule::call(fn () => Artisan::call('vollna:poll-api --quiet-ok'))
    ->everyMinute()
    ->name('vollna-poll-api')
    ->withoutOverlapping(10);

// Dead-man's switch: alerts (once per incident) if Vollna intake has
// gone quiet past the configured window. See VollnaCheckSilenceCommand.
Schedule::call(fn () => Artisan::call('vollna:check-silence'))
    ->everyFifteenMinutes()
    ->name('vollna-check-silence')
    ->withoutOverlapping(10);

// OpenClaw + WhatsApp watchdog — one alert per outage, silent recovery.
Schedule::call(fn () => Artisan::call('health:check'))
    ->everyFiveMinutes()
    ->name('health-check')
    ->withoutOverlapping(10);

// Golden-window safety net: up to two WhatsApp nudges (45min, 90min) for a
// fresh 8+ lead still sitting unbid. Every 5 minutes keeps the drift off
// those marks small without adding a third scheduler cadence to reason about.
Schedule::call(fn () => Artisan::call('leads:send-reminders'))
    ->everyFiveMinutes()
    ->name('lead-reminders')
    ->withoutOverlapping(10);

// Drains the queue (scoring, proposals, notifications) with a hard time
// box, then exits; the next minute's run continues where it left off.
Schedule::call(fn () => Artisan::call('queue:work --stop-when-empty --max-time=45'))
    ->everyMinute()
    ->name('queue-drain')
    ->withoutOverlapping(15);

// Cron heartbeat — every task above (queue draining, health checks, the
// dead-man's switch itself) rides the single hPanel `schedule:run` cron,
// so when that cron dies NOTHING can alert about it. This stamp lets the
// Health page show "scheduler last ran X ago" from a plain web request.
// Learned the hard way: the cron died on 2026-07-16 and 72 jobs piled up
// silently for two days.
Schedule::call(fn () => Cache::forever('cron:last_tick', now()->toIso8601String()))
    ->everyMinute()
    ->name('cron-heartbeat');

// External dead-man's-switch ping. Runs AFTER cron:last_tick above (schedule
// events fire in file order within one schedule:run process). No-op until
// heartbeat_ping_url is set in Settings > Bidding Rules.
//
// What this actually proves, verified live 2026-07-23: only that the OS cron
// is still invoking `schedule:run` at all — the ONE failure mode nothing else
// in this app can detect (that's the 2026-07-16 incident this exists for).
// It does NOT prove every task this minute succeeded: Laravel's scheduler
// (ScheduleRunCommand::runEvent) catches each event's exception individually
// and keeps running the rest of the list, so a failing vollna-poll-api or
// queue-drain does not stop cron-heartbeat or this ping from running right
// after it. Confirmed live: a deliberately-throwing task placed immediately
// before this one still let the ping fire, with the failure correctly logged
// to laravel.log via Laravel's own exception reporting. That's correct scope,
// not a gap — a single task failing is already handled by its own targeted
// alert (health:check, vollna:check-silence, the AI failure alerts), each
// once-per-incident; this ping would just add noise if it also reacted to
// those. This is a scheduler-alive signal ONLY.
Schedule::call(fn () => Artisan::call('ops:heartbeat'))
    ->everyMinute()
    ->name('external-heartbeat');
