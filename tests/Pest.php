<?php

use App\Models\Reservation;
use App\Models\ReservationSetting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

// Typed against the interface, not Illuminate\Support\Carbon: this app
// configures Date::use(CarbonImmutable::class) (see AppServiceProvider), so
// now()->addDay() etc. actually return Carbon\CarbonImmutable instances.
function makeReservation(User $owner, CarbonInterface $start, CarbonInterface $end, array $attributes = []): Reservation
{
    return Reservation::create(array_merge([
        'user_id' => $owner->id,
        'starts_at' => $start,
        'ends_at' => $end,
        'group_size' => 3,
        'price_cents' => 3000,
        'status' => 'active',
    ], $attributes));
}

function makeReservationSettings(array $attributes = []): ReservationSetting
{
    return ReservationSetting::forceCreate(array_merge([
        'id' => 1,
        'price_cents_per_person_per_hour' => 500,
        'min_group_size' => 3,
        'min_duration_minutes' => 30,
        'max_duration_minutes' => 240,
        'opening_time' => '08:00',
        'closing_time' => '23:00',
        'cancellation_cutoff_hours' => 24,
    ], $attributes));
}
