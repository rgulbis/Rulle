<?php

use App\Models\CheckInEvent;
use App\Models\User;

test('the site root is a public home page, not a login wall', function () {
    $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->component('welcome'));

    // A logged-in visitor gets the same public page rather than being
    // bounced to their dashboard.
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertInertia(fn ($page) => $page->component('welcome'));
});

test('the home page shows the live headcount', function () {
    User::factory()->count(2)->create()->each(
        fn (User $user) => CheckInEvent::create(['user_id' => $user->id, 'checked_in' => true]),
    );

    $this->get('/')->assertInertia(fn ($page) => $page->where('checkedInCount', 2));
});

test('the home page lists active plans without their Stripe ids', function () {
    makeSubscriptionType(['name' => 'Day pass', 'price_cents' => 500]);
    makeSubscriptionType(['name' => 'Retired', 'active' => false]);

    $this->get('/')->assertInertia(fn ($page) => $page
        ->has('plans', 1)
        ->where('plans.0.name', 'Day pass')
        ->missing('plans.0.stripe_price_id')
        ->missing('plans.0.stripe_product_id')
    );
});

test("the home page shows today's reservations but not who booked them", function () {
    $owner = User::factory()->create();
    makeReservation($owner, today()->setTime(14, 0), today()->setTime(15, 0));

    $this->get('/')->assertInertia(fn ($page) => $page
        ->has('todaysReservations', 1)
        ->missing('todaysReservations.0.user_id')
    );
});

test('the home page shows group booking pricing from the reservation settings', function () {
    makeReservationSettings(['price_cents_per_person_per_hour' => 700, 'min_group_size' => 4]);

    $this->get('/')->assertInertia(fn ($page) => $page
        ->where('groupBooking.price_cents_per_person_per_hour', 700)
        ->where('groupBooking.min_group_size', 4)
    );
});

test('every page shares the park opening hours', function () {
    makeReservationSettings(['opening_time' => '10:00', 'closing_time' => '20:00']);

    $this->travelTo(today()->setTime(12, 0));
    $this->get('/livestream')->assertInertia(fn ($page) => $page
        ->where('park.opening_time', '10:00')
        ->where('park.closing_time', '20:00')
        ->where('park.is_open', true)
    );

    $this->travelTo(today()->setTime(21, 0));
    $this->get('/livestream')->assertInertia(fn ($page) => $page->where('park.is_open', false));
});
