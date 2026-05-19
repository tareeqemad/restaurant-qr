<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
|
| Reservation reminders fire every 5 minutes against a 60-minute lookahead.
| The command de-duplicates internally (queries `notifications` table for
| any prior reminder of the same reservation), so this cadence is safe
| to lower if needed without spamming staff.
|
| Run `php artisan schedule:work` in dev or wire `* * * * * php artisan
| schedule:run` in cron for production.
*/
Schedule::command('notifications:reservation-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Attendance — auto-close stale shifts
|--------------------------------------------------------------------------
|
| Staffer forgets to clock out → record stays open and the system keeps
| counting "working hours" indefinitely. Hourly sweep closes any shift
| open more than 24h, capping the credited time at 12h so payroll doesn't
| reward the unattended interval.
*/
Schedule::command('attendance:close-stale')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Inventory — nightly snapshot
|--------------------------------------------------------------------------
|
| Records close-of-day stock + value per (ingredient, branch). Powers the
| stock-trend report without rerunning aggregates over journal_lines /
| inventory_movements every page load. Idempotent — re-running for the
| same date overwrites that day's row instead of duplicating.
*/
Schedule::command('app:snapshot-inventory')
    ->dailyAt('23:59')
    ->withoutOverlapping()
    ->runInBackground();
