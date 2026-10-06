<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('payments:retry-refunds')->everyTenMinutes()->withoutOverlapping();
Schedule::command('db:backup --prefix=daily --keep=14')->dailyAt('04:00');
Schedule::command('checkins:close-stale')->everyFifteenMinutes()->withoutOverlapping();
