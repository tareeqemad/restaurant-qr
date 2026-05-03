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
