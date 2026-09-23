<?php

use App\Models\Reservation;
use App\Models\User;

test('price is per person, per hour, rounded up for partial hours', function () {
    $settings = makeReservationSettings(['price_cents_per_person_per_hour' => 500]);

    expect($settings->priceFor(60, 3))->toBe(1500);
    expect($settings->priceFor(30, 3))->toBe(750);
    expect($settings->priceFor(90, 4))->toBe(3000);
    // 1 minute at 500/person/hr for 4 people = 33.33...; exercises the
    // ceil() rounding rather than landing on a whole number.
    expect($settings->priceFor(1, 4))->toBe(34);
});

test('rejects a reservation shorter than the configured minimum', function () {
    makeReservationSettings();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/reservations', [
        'starts_at' => now()->addDay()->toDateTimeString(),
        'duration_minutes' => 15,
        'group_size' => 3,
    ]);

    $response->assertSessionHasErrors('duration_minutes');
    expect(Reservation::count())->toBe(0);
});

test('rejects a reservation longer than the configured maximum', function () {
    makeReservationSettings();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/reservations', [
        'starts_at' => now()->addDay()->toDateTimeString(),
        'duration_minutes' => 300,
        'group_size' => 3,
    ]);

    $response->assertSessionHasErrors('duration_minutes');
    expect(Reservation::count())->toBe(0);
});

test('rejects a group smaller than the configured minimum', function () {
    makeReservationSettings(['min_group_size' => 3]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/reservations', [
        'starts_at' => now()->addDay()->toDateTimeString(),
        'duration_minutes' => 60,
        'group_size' => 2,
    ]);

    $response->assertSessionHasErrors('group_size');
    expect(Reservation::count())->toBe(0);
});

test('rejects a reservation outside operating hours', function () {
    makeReservationSettings(['opening_time' => '08:00', 'closing_time' => '23:00']);
    $user = User::factory()->create();

    $tooEarly = $this->actingAs($user)->post('/reservations', [
        'starts_at' => now()->addDay()->setTime(7, 0)->toDateTimeString(),
        'duration_minutes' => 60,
        'group_size' => 3,
    ]);
    $tooEarly->assertSessionHasErrors('starts_at');

    $tooLate = $this->actingAs($user)->post('/reservations', [
        'starts_at' => now()->addDay()->setTime(22, 30)->toDateTimeString(),
        'duration_minutes' => 60,
        'group_size' => 3,
    ]);
    $tooLate->assertSessionHasErrors('starts_at');

    expect(Reservation::count())->toBe(0);
});

test('rejects a reservation that starts in the past', function () {
    makeReservationSettings();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/reservations', [
        'starts_at' => now()->subHour()->toDateTimeString(),
        'duration_minutes' => 60,
        'group_size' => 3,
    ]);

    $response->assertSessionHasErrors('starts_at');
});

test('rejects a reservation that overlaps an existing one', function () {
    makeReservationSettings();
    $existingOwner = User::factory()->create();
    $start = now()->addDay()->setTime(14, 0);
    makeReservation($existingOwner, $start, $start->copy()->addHour());

    $user = User::factory()->create();
    $response = $this->actingAs($user)->post('/reservations', [
        // Overlaps the middle of the existing 14:00-15:00 reservation.
        'starts_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
        'duration_minutes' => 60,
        'group_size' => 3,
    ]);

    $response->assertSessionHasErrors('starts_at');
    expect(Reservation::where('user_id', $user->id)->count())->toBe(0);
});

test('a cancelled reservation does not block the same slot', function () {
    $existingOwner = User::factory()->create();
    $start = now()->addDay()->setTime(14, 0);
    makeReservation($existingOwner, $start, $start->copy()->addHour(), ['status' => 'cancelled']);

    expect(Reservation::overlapping($start, $start->copy()->addHour())->exists())->toBeFalse();
});

test('a stale pending reservation stops blocking after the grace window', function () {
    $existingOwner = User::factory()->create();
    $start = now()->addDay()->setTime(14, 0);
    $stale = makeReservation($existingOwner, $start, $start->copy()->addHour(), ['status' => 'pending']);
    $stale->forceFill(['created_at' => now()->subHour()])->save();

    expect(Reservation::overlapping($start, $start->copy()->addHour())->exists())->toBeFalse();
});

test('a pending reservation is not overlapped by another active one until that other reservation exists', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $start = now()->addDay()->setTime(14, 0);

    $pending = makeReservation($owner, $start, $start->copy()->addHour(), ['status' => 'pending']);
    expect($pending->overlappedByAnotherActiveReservation())->toBeFalse();

    makeReservation($other, $start->copy()->addMinutes(30), $start->copy()->addHours(2), ['status' => 'active']);
    expect($pending->overlappedByAnotherActiveReservation())->toBeTrue();
});

test('a non-overlapping active reservation does not count as a conflict', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $start = now()->addDay()->setTime(14, 0);

    $pending = makeReservation($owner, $start, $start->copy()->addHour(), ['status' => 'pending']);
    makeReservation($other, $start->copy()->addHours(2), $start->copy()->addHours(3), ['status' => 'active']);

    expect($pending->overlappedByAnotherActiveReservation())->toBeFalse();
});

test('reservation owner and participants are not blocked from entering, other customers are', function () {
    $owner = User::factory()->create();
    $participant = User::factory()->create();
    $outsider = User::factory()->create();

    $reservation = makeReservation($owner, now()->subMinutes(10), now()->addHour());
    $reservation->participants()->attach($participant->id);

    expect($reservation->includesParticipant($owner))->toBeTrue();
    expect($reservation->includesParticipant($participant))->toBeTrue();
    expect($reservation->includesParticipant($outsider))->toBeFalse();
    expect(Reservation::activeNow()->is($reservation))->toBeTrue();
});

test('only the reservation owner can add a participant', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $someoneElse = User::factory()->create();
    $reservation = makeReservation($owner, now()->addHour(), now()->addHours(2));

    $response = $this->actingAs($someoneElse)->post("/reservations/{$reservation->id}/participants", [
        'user_id' => $friend->id,
    ]);

    $response->assertForbidden();
    expect($reservation->participants()->count())->toBe(0);
});

test('owner can add an existing customer as a participant', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = makeReservation($owner, now()->addHour(), now()->addHours(2), ['group_size' => 3]);

    $response = $this->actingAs($owner)->post("/reservations/{$reservation->id}/participants", [
        'user_id' => $friend->id,
    ]);

    $response->assertRedirect();
    expect($reservation->participants()->whereKey($friend->id)->exists())->toBeTrue();
});

test('cannot add a participant beyond the paid group size', function () {
    $owner = User::factory()->create();
    // group_size 3 = owner + 2 named participants max.
    $reservation = makeReservation($owner, now()->addHour(), now()->addHours(2), ['group_size' => 3]);
    $reservation->participants()->attach(User::factory()->count(2)->create()->pluck('id'));

    $oneMore = User::factory()->create();
    $response = $this->actingAs($owner)->post("/reservations/{$reservation->id}/participants", [
        'user_id' => $oneMore->id,
    ]);

    $response->assertSessionHasErrors('user_id');
    expect($reservation->participants()->count())->toBe(2);
});

test('cannot add the owner themselves as a participant', function () {
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addHour(), now()->addHours(2));

    $response = $this->actingAs($owner)->post("/reservations/{$reservation->id}/participants", [
        'user_id' => $owner->id,
    ]);

    $response->assertSessionHasErrors('user_id');
});

test('cannot add staff as a participant', function () {
    $owner = User::factory()->create();
    $employee = User::factory()->create(['role' => 'employee']);
    $reservation = makeReservation($owner, now()->addHour(), now()->addHours(2));

    $response = $this->actingAs($owner)->post("/reservations/{$reservation->id}/participants", [
        'user_id' => $employee->id,
    ]);

    $response->assertSessionHasErrors('user_id');
});

test('owner can remove a participant', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = makeReservation($owner, now()->addHour(), now()->addHours(2));
    $reservation->participants()->attach($friend->id);

    $response = $this->actingAs($owner)->delete("/reservations/{$reservation->id}/participants/{$friend->id}");

    $response->assertRedirect();
    expect($reservation->participants()->whereKey($friend->id)->exists())->toBeFalse();
});

test('user search excludes staff and the searching user themselves', function () {
    $searcher = User::factory()->create(['name' => 'Searching Sam']);
    User::factory()->create(['name' => 'Findable Fiona']);
    User::factory()->create(['role' => 'employee', 'name' => 'Findable Frank']);

    $response = $this->actingAs($searcher)->getJson('/reservations/users/search?q=Findable');

    $response->assertOk();
    $names = collect($response->json())->pluck('name');
    expect($names)->toContain('Findable Fiona');
    expect($names)->not->toContain('Findable Frank');
});

test('user search masks emails instead of exposing them in full', function () {
    $searcher = User::factory()->create();
    User::factory()->create(['name' => 'Findable Fiona', 'email' => 'fiona@example.com']);

    $response = $this->actingAs($searcher)->getJson('/reservations/users/search?q=Findable');

    $response->assertOk();
    expect(collect($response->json())->pluck('email'))
        ->toContain('f***@example.com')
        ->not->toContain('fiona@example.com');
});

test('reservations index shows upcoming reservations without exposing who booked them', function () {
    $owner = User::factory()->create();
    makeReservation($owner, now()->addDay(), now()->addDay()->addHour());

    $viewer = User::factory()->create();
    $response = $this->actingAs($viewer)->get('/reservations');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('upcoming', 1)
        ->where('upcoming.0.id', fn ($id) => is_int($id))
        ->missing('upcoming.0.user_id')
    );
});

test('a named participant sees the reservation in their own list, but not as the owner', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    $reservation->participants()->attach($friend->id);

    $ownerResponse = $this->actingAs($owner)->get('/reservations');
    $ownerResponse->assertInertia(fn ($page) => $page
        ->has('mine', 1)
        ->where('mine.0.is_owner', true)
        ->missing('mine.0.user_id')
    );

    $friendResponse = $this->actingAs($friend)->get('/reservations');
    $friendResponse->assertInertia(fn ($page) => $page
        ->has('mine', 1)
        ->where('mine.0.is_owner', false)
        ->missing('mine.0.user_id')
    );
});

test('only the reservation owner can resume payment', function () {
    $owner = User::factory()->create();
    $someoneElse = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour(), ['status' => 'pending']);

    $response = $this->actingAs($someoneElse)->post("/reservations/{$reservation->id}/resume");

    $response->assertForbidden();
});

test('cannot resume a reservation that is no longer pending', function () {
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour(), ['status' => 'active']);

    $response = $this->actingAs($owner)->post("/reservations/{$reservation->id}/resume");

    $response->assertStatus(422);
});

test('cancelling a pending reservation frees the slot', function () {
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour(), ['status' => 'pending']);

    $response = $this->actingAs($owner)->get("/reservations/{$reservation->id}/cancel");

    $response->assertRedirect(route('reservations.index'));
    expect($reservation->fresh()->status)->toBe('cancelled');
});

test('a reservation well before its cutoff is eligible for a cancellation refund', function () {
    $settings = makeReservationSettings(['cancellation_cutoff_hours' => 24]);

    expect($settings->isEligibleForCancellationRefund(now()->addDays(2)))->toBeTrue();
    expect($settings->isEligibleForCancellationRefund(now()->addHours(23)))->toBeFalse();
});

test('a customer can cancel a paid reservation before it starts, and it frees the slot', function () {
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDays(3), now()->addDays(3)->addHour(), ['status' => 'active']);

    $response = $this->actingAs($owner)->get("/reservations/{$reservation->id}/cancel");

    $response->assertRedirect(route('reservations.index'));
    expect($reservation->fresh()->status)->toBe('cancelled');
});

test('cancelling a paid reservation with no checkout session on record does not claim a refund happened', function () {
    makeReservationSettings(['cancellation_cutoff_hours' => 24]);
    $owner = User::factory()->create();
    // No stripe_checkout_session_id set — nothing for the controller to
    // actually refund against, even though it's well outside the cutoff.
    $reservation = makeReservation($owner, now()->addDays(3), now()->addDays(3)->addHour(), ['status' => 'active']);

    $response = $this->actingAs($owner)->get("/reservations/{$reservation->id}/cancel");

    $response->assertRedirect(route('reservations.index'));
    $response->assertSessionHas('status', 'reservation-cancelled-no-refund');
    expect($reservation->fresh()->status)->toBe('cancelled');
});

test('a paid reservation that has already started cannot be cancelled', function () {
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->subMinutes(10), now()->addHour(), ['status' => 'active']);

    $response = $this->actingAs($owner)->get("/reservations/{$reservation->id}/cancel");

    $response->assertRedirect(route('reservations.index'));
    $response->assertSessionHas('status', 'reservation-cannot-cancel');
    expect($reservation->fresh()->status)->toBe('active');
});

test('only the reservation owner can cancel it', function () {
    $owner = User::factory()->create();
    $someoneElse = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDays(3), now()->addDays(3)->addHour(), ['status' => 'active']);

    $this->actingAs($someoneElse)->get("/reservations/{$reservation->id}/cancel");

    expect($reservation->fresh()->status)->toBe('active');
});
