<?php

use App\Models\CheckInEvent;
use App\Models\Reservation;
use App\Models\User;

test('a guest can view the livestream page without logging in', function () {
    $response = $this->get('/livestream');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('livestream/index'));
});

test('any logged-in role can also view the livestream page', function () {
    foreach (['user', 'employee', 'admin'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]))
            ->get('/livestream')
            ->assertOk();
    }
});

test('the site root is the same public livestream page, not a login wall', function () {
    $this->get('/')->assertInertia(fn ($page) => $page->component('livestream/index'));

    // Previously '/' was guest-only and bounced a logged-in visitor to their
    // dashboard — it should just show the same public page now instead.
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertInertia(fn ($page) => $page->component('livestream/index'));
});

test('the livestream page shows how many people are currently checked in', function () {
    User::factory()->count(2)->create()->each(
        fn (User $user) => CheckInEvent::create(['user_id' => $user->id, 'checked_in' => true]),
    );
    // Never checked in at all — should not count towards the headcount.
    User::factory()->count(3)->create();

    $this->get('/livestream')->assertInertia(fn ($page) => $page
        ->where('checkedInCount', 2)
    );
});

test("the livestream page shows today's reservations but not who booked them", function () {
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, today()->setTime(14, 0), today()->setTime(15, 0));
    // A reservation for a different day shouldn't show up in today's list.
    makeReservation($owner, today()->addDay()->setTime(14, 0), today()->addDay()->setTime(15, 0));

    $response = $this->get('/livestream');

    $response->assertInertia(fn ($page) => $page
        ->has('todaysReservations', 1)
        ->where('todaysReservations.0.starts_at', $reservation->starts_at->toJSON())
        ->missing('todaysReservations.0.user_id')
    );
});

test('a cancelled reservation does not appear in the livestream schedule', function () {
    $owner = User::factory()->create();
    makeReservation($owner, today()->setTime(14, 0), today()->setTime(15, 0), ['status' => 'cancelled']);

    $this->get('/livestream')->assertInertia(fn ($page) => $page->has('todaysReservations', 0));
});
