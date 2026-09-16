<?php

use App\Models\CheckInEvent;
use App\Models\User;
use App\Support\CheckInOccupancy;
use Illuminate\Support\Carbon;

function logEvent(User $user, bool $checkedIn, Carbon $at): void
{
    // ::insert() bypasses mass-assignment protection (unlike ::create()),
    // which is required here since created_at/updated_at aren't fillable.
    CheckInEvent::insert([
        'user_id' => $user->id,
        'checked_in' => $checkedIn,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

test('occupancy rises on check-in and falls on check-out', function () {
    $start = Carbon::parse('2026-01-01 00:00:00');
    $user = User::factory()->create();

    logEvent($user, true, $start->copy()->addHours(2));
    logEvent($user, false, $start->copy()->addHours(5));

    $series = CheckInOccupancy::hourly($start, 8);

    expect($series['values'])->toBe([0, 0, 1, 1, 1, 0, 0, 0]);
});

test('a rider already inside before the window counts from hour zero', function () {
    $start = Carbon::parse('2026-01-01 00:00:00');
    $user = User::factory()->create();

    // Checked in well before the window we're asking about, never checked
    // out — should already be counted as occupying the very first bucket.
    logEvent($user, true, $start->copy()->subDays(1));

    $series = CheckInOccupancy::hourly($start, 3);

    expect($series['values'])->toBe([1, 1, 1]);
});

test('multiple riders overlap correctly', function () {
    $start = Carbon::parse('2026-01-01 00:00:00');
    [$a, $b] = User::factory()->count(2)->create();

    logEvent($a, true, $start->copy()->addHour());
    logEvent($b, true, $start->copy()->addHours(2));
    logEvent($a, false, $start->copy()->addHours(3));
    logEvent($b, false, $start->copy()->addHours(4));

    $series = CheckInOccupancy::hourly($start, 6);

    expect($series['values'])->toBe([0, 1, 2, 1, 0, 0]);
});

test('never goes negative even with an unmatched check-out', function () {
    $start = Carbon::parse('2026-01-01 00:00:00');
    $user = User::factory()->create();

    // No prior check-in logged at all (e.g. it happened before this
    // feature existed) — the checkout shouldn't drag the count negative.
    logEvent($user, false, $start->copy()->addHours(1));

    $series = CheckInOccupancy::hourly($start, 3);

    expect($series['values'])->toBe([0, 0, 0]);
});

test('typicalCheckInsByHour collapses check-ins across dates into a 24-hour profile', function () {
    $user = User::factory()->create();

    // Two different days, both with a 15:00 check-in — should collapse
    // into hour 15 having a count of 2, regardless of the date.
    logEvent($user, true, Carbon::parse('2026-01-01 15:00:00'));
    logEvent($user, true, Carbon::parse('2026-01-02 15:30:00'));
    logEvent($user, true, Carbon::parse('2026-01-03 09:00:00'));
    // Check-outs shouldn't count as "busy" traffic.
    logEvent($user, false, Carbon::parse('2026-01-01 16:00:00'));

    $profile = CheckInOccupancy::typicalCheckInsByHour();

    expect($profile)->toHaveCount(24);
    expect($profile[15])->toBe(2);
    expect($profile[9])->toBe(1);
    expect($profile[16])->toBe(0);
});
