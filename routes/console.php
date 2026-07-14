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
| Table sessions — auto-close idle zero-exposure sessions
|--------------------------------------------------------------------------
|
| A guest walks out without the session ever closing → the row stays
| `active` forever (the only TTL was the customer's cookie), the table
| looks permanently occupied, and the NEXT guest scanning the same QR
| joins the ghost party's session — their orders merge onto a stranger's
| bill. Sweep every 10 minutes: close sessions idle beyond
| restaurant.order.session_idle_close_minutes (default 240) — but ONLY
| when no money is at stake (no orders / all cancelled / invoice fully
| paid). Sessions with unpaid orders are logged for the cashier instead.
*/
Schedule::command('table-sessions:close-idle')
    ->everyTenMinutes()
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

/*
|--------------------------------------------------------------------------
| Database — nightly safety-net backup
|--------------------------------------------------------------------------
|
| On a local/on-prem server the branch is the source of truth between cloud
| syncs (see docs/offline-sync-architecture.md). A gzipped mysqldump — kept
| locally and optionally pushed off-site (config/backup.php) — guards against
| branch hardware failure losing the day's data. Runs at 03:30, after the
| 23:59 inventory snapshot and at the quiet end of service.
*/
Schedule::command('backup:run')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Cloud sync — drain on reconnect
|--------------------------------------------------------------------------
|
| Branch nodes try to sync every minute. The command is a cheap no-op unless
| SYNC_ENABLED=true and SYNC_ROLE=branch, and it just records "offline" when
| the cloud is unreachable — so the moment the internet returns, the pending
| config (down) and transactions (up) drain automatically without anyone
| pressing a button. See docs/offline-sync-architecture.md.
*/
Schedule::command('sync:run')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
