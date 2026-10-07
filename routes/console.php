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

// A refund that was promised but not confirmed by Stripe (outage, timeout) is
// retried here until it goes through. Safe to repeat: refunds carry an
// idempotency key.
Schedule::command('payments:retry-refunds')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onFailure(fn () => Log::error('Some refunds are still failing; see payments:retry-refunds.'));
