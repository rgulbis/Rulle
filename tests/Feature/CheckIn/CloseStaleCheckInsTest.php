<?php

use App\Events\OccupancyUpdated;
use App\Events\UserCheckInStatusUpdated;
use App\Models\CheckInEvent;
use App\Models\User;
use App\Support\CheckInOccupancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

function checkInAt(User $user, string $when, bool $in = true): void
{
    $at = Carbon::parse($when);

    (new CheckInEvent)->forceFill([
        'user_id' => $user->id,
        'checked_in' => $in,
        'created_at' => $at,
        'updated_at' => $at,
    ])->save();
}

beforeEach(function () {
    makeReservationSettings(['opening_time' => '08:00', 'closing_time' => '23:00']);
    config(['checkin.max_visit_minutes' => 720]);
});

test('a rider still inside after closing time is checked out, stamped at closing time', function () {
    $this->travelTo(Carbon::parse('2026-10-11 10:00'));
    $user = User::factory()->create();
    checkInAt($user, '2026-10-11 20:00');

    $this->travelTo(Carbon::parse('2026-10-11 23:05'));
    $this->artisan('checkins:close-stale')->expectsOutput('Stale check-ins closed: 1.')->assertSuccessful();

    $last = CheckInEvent::where('user_id', $user->id)->latest('id')->first();
    expect($last->checked_in)->toBeFalse()
        ->and($last->created_at->toDateTimeString())->toBe('2026-10-11 23:00:00')
        ->and($user->isCurrentlyCheckedIn())->toBeFalse();
});

test('nobody is closed before the park closes', function () {
    $this->travelTo(Carbon::parse('2026-10-11 22:59'));
    $user = User::factory()->create();
    checkInAt($user, '2026-10-11 20:00');

    $this->artisan('checkins:close-stale')->expectsOutput('Stale check-ins closed: 0.')->assertSuccessful();

    expect(CheckInEvent::where('user_id', $user->id)->count())->toBe(1)
        ->and($user->isCurrentlyCheckedIn())->toBeTrue();
});

test('a check-in from before the previous closing is closed at that closing, even if the job was down overnight', function () {
    $user = User::factory()->create();
    checkInAt($user, '2026-10-10 21:00');

    $this->travelTo(Carbon::parse('2026-10-11 09:00'));
    $this->artisan('checkins:close-stale')->assertSuccessful();

    $last = CheckInEvent::where('user_id', $user->id)->latest('id')->first();
    expect($last->checked_in)->toBeFalse()
        ->and($last->created_at->toDateTimeString())->toBe('2026-10-10 23:00:00');
});

test('someone who arrives after closing time is closed at the next closing', function () {
    $user = User::factory()->create();
    checkInAt($user, '2026-10-11 23:30');

    $this->travelTo(Carbon::parse('2026-10-12 08:00'));
    $this->artisan('checkins:close-stale')->expectsOutput('Stale check-ins closed: 0.');

    $this->travelTo(Carbon::parse('2026-10-12 23:01'));
    $this->artisan('checkins:close-stale')->expectsOutput('Stale check-ins closed: 1.');
});

test('the maximum visit length closes a visit before closing time, stamped when it ran out', function () {
    config(['checkin.max_visit_minutes' => 240]);
    $user = User::factory()->create();
    checkInAt($user, '2026-10-11 09:00');

    $this->travelTo(Carbon::parse('2026-10-11 14:00'));
    $this->artisan('checkins:close-stale')->assertSuccessful();

    $last = CheckInEvent::where('user_id', $user->id)->latest('id')->first();
    expect($last->checked_in)->toBeFalse()
        ->and($last->created_at->toDateTimeString())->toBe('2026-10-11 13:00:00');
});

test('riders who already left, or are mid-visit, are not touched', function () {
    $this->travelTo(Carbon::parse('2026-10-11 23:30'));
    [$left, $inside] = User::factory()->count(2)->create();
    checkInAt($left, '2026-10-11 18:00');
    checkInAt($left, '2026-10-11 19:00', in: false);
    checkInAt($inside, '2026-10-11 23:10');

    $this->artisan('checkins:close-stale')->assertSuccessful();

    expect(CheckInEvent::where('user_id', $left->id)->count())->toBe(2)
        ->and(CheckInEvent::where('user_id', $inside->id)->count())->toBe(1);
});

test('running it again does nothing more', function () {
    $user = User::factory()->create();
    checkInAt($user, '2026-10-11 20:00');
    $this->travelTo(Carbon::parse('2026-10-11 23:30'));

    $this->artisan('checkins:close-stale')->expectsOutput('Stale check-ins closed: 1.');
    $this->artisan('checkins:close-stale')->expectsOutput('Stale check-ins closed: 0.');

    expect(CheckInEvent::where('user_id', $user->id)->count())->toBe(2);
});

test('it tells the rider\'s dashboard and the live headcount', function () {
    Event::fake([UserCheckInStatusUpdated::class, OccupancyUpdated::class]);
    $user = User::factory()->create();
    checkInAt($user, '2026-10-11 20:00');
    $this->travelTo(Carbon::parse('2026-10-11 23:30'));

    $this->artisan('checkins:close-stale')->assertSuccessful();

    Event::assertDispatched(UserCheckInStatusUpdated::class, fn ($e) => $e->user->is($user));
    Event::assertDispatched(OccupancyUpdated::class);
});

test('it stays quiet when there is nothing to close', function () {
    Event::fake([UserCheckInStatusUpdated::class, OccupancyUpdated::class]);

    $this->artisan('checkins:close-stale')->assertSuccessful();

    Event::assertNotDispatched(OccupancyUpdated::class);
});

test('the live headcount and a rider\'s own status ignore a check-in older than the maximum visit', function () {
    config(['checkin.max_visit_minutes' => 240]);
    $this->travelTo(Carbon::parse('2026-10-11 12:00'));
    [$recent, $old] = User::factory()->count(2)->create();
    checkInAt($recent, '2026-10-11 10:00');
    checkInAt($old, '2026-10-11 07:00');

    expect(CheckInOccupancy::currentlyCheckedInCount())->toBe(1)
        ->and($recent->isCurrentlyCheckedIn())->toBeTrue()
        ->and($old->isCurrentlyCheckedIn())->toBeFalse();
});

test('the job is on the schedule', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('checkins:close-stale')->assertSuccessful();
});
