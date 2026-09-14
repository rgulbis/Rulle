<?php

use App\Models\CheckInEvent;
use App\Models\User;

test('refuses to run in production', function () {
    app()['env'] = 'production';

    $this->artisan('checkins:time-skip')->assertExitCode(1);

    expect(CheckInEvent::count())->toBe(0);
});

test('backfills check-in events without touching live checked-in status', function () {
    User::factory()->count(5)->create(['role' => 'user', 'checked_in' => false]);

    $this->artisan('checkins:time-skip', ['--days' => 3, '--users' => 5])
        ->assertExitCode(0);

    expect(CheckInEvent::count())->toBeGreaterThan(0);
    expect(User::where('checked_in', true)->count())->toBe(0);
});
