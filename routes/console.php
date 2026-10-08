<?php

use App\Models\UsedQrToken;
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

// Checkouts nobody finished are closed once Stripe's own session has expired.
// The `checkout.session.expired` webhook does the same; this covers a missed
// delivery or an endpoint without that event.
Schedule::command('payments:expire-abandoned')
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(fn () => Log::error('Abandoned checkouts could not be closed; see payments:expire-abandoned.'));

// Saving a plan never waits for Stripe, so a plan whose sync failed (Stripe
// down, a typo'd price) is retried here. Safe to repeat: each step is
// recorded as it completes and the Stripe calls carry idempotency keys.
Schedule::command('plans:sync-stripe')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onFailure(fn () => Log::error('Some plans are still not synced to Stripe; see plans:sync-stripe.'));

// Riders who never got scanned out (left without the exit scan, phone died)
// would otherwise count as inside indefinitely. Closing time is a setting an
// admin can change, so rather than a fixed cron time this runs every few
// minutes and checks out anyone whose park-closing or maximum visit length
// has passed — stamped with that moment, not the time the job ran, so the
// occupancy chart comes out the same as if it had run at closing. Safe to
// repeat, and catches up after the scheduler was down.
Schedule::command('checkins:close-stale')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onFailure(fn () => Log::error('Stale check-ins could not be closed; see checkins:close-stale.'));

// Spent QR token records only matter until the token would have expired.
Schedule::command('model:prune', ['--model' => [UsedQrToken::class]])
    ->daily()
    ->onFailure(fn () => Log::warning('Could not prune spent QR tokens.'));
