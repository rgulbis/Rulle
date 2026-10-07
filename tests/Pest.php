<?php

use App\Models\Reservation;
use App\Models\ReservationSetting;
use App\Models\SubscriptionType;
use App\Models\User;
use App\Support\CheckIn\QrToken;
use App\Support\Payments\StripeGateway;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeStripeGateway;
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

// No RefreshDatabase: these tests build their own on-disk SQLite file, which
// separate PHP processes can share (an in-memory database can't be).
pest()->extend(TestCase::class)->in('Parallel');

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
    $status = $attributes['status'] ?? 'active';

    return Reservation::create(array_merge([
        'user_id' => $owner->id,
        'starts_at' => $start,
        'ends_at' => $end,
        'group_size' => 3,
        'price_cents' => 3000,
        'status' => $status,
        // An active reservation has been paid for; anything else hasn't.
        'payment_status' => $status === 'active' ? 'paid' : 'unpaid',
    ], $attributes));
}

// Swaps the real Stripe API for an in-memory one for the rest of the test.
function fakeStripe(): FakeStripeGateway
{
    $fake = new FakeStripeGateway;
    app()->instance(StripeGateway::class, $fake);

    return $fake;
}

function makeReservationSettings(array $attributes = []): ReservationSetting
{
    return ReservationSetting::forceCreate(array_merge([
        'id' => 1,
        'price_cents_per_person_per_hour' => 500,
        'min_group_size' => 3,
        'max_group_size' => 50,
        'min_duration_minutes' => 30,
        'max_duration_minutes' => 240,
        'opening_time' => '08:00',
        'closing_time' => '23:00',
        'cancellation_cutoff_hours' => 24,
    ], $attributes));
}

// Saving a plan never talks to Stripe (PlanStripeSync does, on request), so
// this has no Stripe product or price until a test syncs it. Defaults to a
// one-time plan since that's the one that produces a Purchase (a recurring
// plan goes through Cashier's own subscriptions instead).
function makeSubscriptionType(array $attributes = []): SubscriptionType
{
    return SubscriptionType::create(array_merge([
        'name' => 'Day pass',
        'price_cents' => 500,
        'billing_interval' => 'one_time',
        'visit_limit' => 1,
    ], $attributes));
}

// What a rider's dashboard would be showing right now: a fresh, unspent entry
// token. Each call is a different token, as each poll of the dashboard is.
function qrTokenFor(User $user): string
{
    return QrToken::issue($user)['token'];
}
