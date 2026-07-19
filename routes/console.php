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
// so new leads now arrive by polling the filter API every 2 minutes.
// Additive only — never deletes (that's the manual Sync now button).
Schedule::call(fn () => Artisan::call('vollna:poll-api --quiet-ok'))
    ->everyTwoMinutes()
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
