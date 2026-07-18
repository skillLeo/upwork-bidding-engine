<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('leads:follow-up-reminders')->daily();

// Dead-man's switch: alerts (once per incident) if Vollna's webhook has
// gone quiet past the configured window. See VollnaCheckSilenceCommand.
Schedule::command('vollna:check-silence')->hourly();

// OpenClaw + WhatsApp watchdog — one alert per outage, silent recovery.
Schedule::command('health:check')->everyFiveMinutes()->withoutOverlapping();

// Lets the whole app run on hosts with no persistent worker process (shared
// hosting + cron only): drains whatever's queued, then exits, every minute.
// Harmless alongside a real `queue:work`/Horizon deployment too — it just
// finds nothing to do and returns immediately.
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();

// Cron heartbeat — every task above (queue draining, health checks, the
// dead-man's switch itself) rides the single hPanel `schedule:run` cron,
// so when that cron dies NOTHING can alert about it. This stamp lets the
// Health page show "scheduler last ran X ago" from a plain web request.
// Learned the hard way: the cron died on 2026-07-16 and 72 jobs piled up
// silently for two days.
Schedule::call(fn () => Cache::forever('cron:last_tick', now()->toIso8601String()))
    ->everyMinute()
    ->name('cron-heartbeat');
