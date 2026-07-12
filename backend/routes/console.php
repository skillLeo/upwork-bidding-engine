<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('leads:follow-up-reminders')->daily();

// Lets the whole app run on hosts with no persistent worker process (shared
// hosting + cron only): drains whatever's queued, then exits, every minute.
// Harmless alongside a real `queue:work`/Horizon deployment too — it just
// finds nothing to do and returns immediately.
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();
