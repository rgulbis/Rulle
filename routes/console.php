<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Four snapshots a day, 28 kept (a week). Run by the `scheduler` service in
// docker-compose.yml; the files land in the separate backups volume.
Schedule::command('db:backup --keep=28')
    ->cron('17 */6 * * *')
    ->withoutOverlapping()
    ->onFailure(fn () => Log::critical('Scheduled database backup failed.'));
