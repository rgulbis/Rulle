<?php

use App\Models\ChatMessage;
use App\Models\Reservation;
use App\Models\User;

/*
| Joining someone else's reservation takes the invitee's consent, seats can't
| be overbooked, and a reservation's group chat ends with the reservation.
*/

function futureReservation(User $owner, array $attributes = []): Reservation
{
    return makeReservation($owner, now()->addDay(), now()->addDay()->addHour(), $attributes + ['group_size' => 3]);
}

function invite(User $owner, Reservation $reservation, User $friend)
{
    return test()->actingAs($owner)->post("/reservations/{$reservation->id}/participants", ['user_id' => $friend->id]);
}

test('an invited customer gets no chat access until they accept', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = futureReservation($owner);

    invite($owner, $reservation, $friend)->assertSessionHas('status', 'participant-invited');

    $this->actingAs($friend)->get("/reservations/{$reservation->id}/chat")->assertForbidden();
    expect($reservation->includesParticipant($friend))->toBeFalse();

    $this->actingAs($friend)->post("/reservations/{$reservation->id}/invitation")
        ->assertRedirect(route('reservations.index'));

    expect($reservation->includesParticipant($friend))->toBeTrue();
    $this->actingAs($friend)->get("/reservations/{$reservation->id}/chat")->assertOk();
});

test('an invitation can be declined, and only the invitee can answer it', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $stranger = User::factory()->create();
    $reservation = futureReservation($owner);
    invite($owner, $reservation, $friend);

    $this->actingAs($stranger)->post("/reservations/{$reservation->id}/invitation")->assertNotFound();
    $this->actingAs($stranger)->delete("/reservations/{$reservation->id}/invitation")->assertNotFound();

    $this->actingAs($friend)->delete("/reservations/{$reservation->id}/invitation")->assertRedirect();
    expect($reservation->allParticipants()->count())->toBe(0);
});

test('an invitee sees the invitation, and nothing else about the reservation', function () {
    $owner = User::factory()->create(['name' => 'Inviting Ivo']);
    $friend = User::factory()->create();
    $reservation = futureReservation($owner);
    invite($owner, $reservation, $friend);

    $this->actingAs($friend)->get('/reservations')->assertInertia(fn ($page) => $page
        ->has('invitations', 1)
        ->where('invitations.0.owner_name', 'Inviting Ivo')
        ->has('mine', 0));
});

test('pending invitations hold a seat so the paid group size cannot be exceeded', function () {
    $owner = User::factory()->create();
    $reservation = futureReservation($owner, ['group_size' => 3]); // owner + 2

    [$a, $b, $c] = User::factory()->count(3)->create()->all();
    invite($owner, $reservation, $a)->assertSessionHasNoErrors();
    invite($owner, $reservation, $b)->assertSessionHasNoErrors();
    invite($owner, $reservation, $c)->assertSessionHasErrors('user_id');

    expect($reservation->allParticipants()->count())->toBe(2);
});

test('inviting the same person twice does not use a second seat', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = futureReservation($owner);

    invite($owner, $reservation, $friend);
    invite($owner, $reservation, $friend);

    expect($reservation->allParticipants()->count())->toBe(1);
});

test('only verified customers can be invited, and only to a paid reservation', function () {
    $owner = User::factory()->create();
    $unverified = User::factory()->unverified()->create();
    $friend = User::factory()->create();
    $reservation = futureReservation($owner);

    invite($owner, $reservation, $unverified)->assertSessionHasErrors('user_id');

    $pending = futureReservation($owner, ['status' => 'pending', 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour()]);
    invite($owner, $pending, $friend)->assertStatus(422);

    $cancelled = makeReservation($owner, now()->addDays(5), now()->addDays(5)->addHour(), ['status' => 'cancelled']);
    invite($owner, $cancelled, $friend)->assertStatus(422);
});

test('the owner can withdraw a pending invitation', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = futureReservation($owner);
    invite($owner, $reservation, $friend);

    $this->actingAs($owner)->delete("/reservations/{$reservation->id}/participants/{$friend->id}")->assertRedirect();

    expect($reservation->allParticipants()->count())->toBe(0);
});

test('user search treats LIKE wildcards literally', function () {
    $searcher = User::factory()->create();
    User::factory()->create(['name' => 'Alice Anderson']);
    User::factory()->create(['name' => 'Bob 100% Real']);

    $this->actingAs($searcher)->getJson('/reservations/users/search?q=%25%25%25')->assertOk()->assertJson([]);
    $this->actingAs($searcher)->getJson('/reservations/users/search?q=___')->assertOk()->assertJson([]);

    $literal = $this->actingAs($searcher)->getJson('/reservations/users/search?q=100%25');
    expect(collect($literal->json())->pluck('name')->all())->toBe(['Bob 100% Real']);
});

test('user search cannot be used to probe email addresses or find unverified accounts', function () {
    $searcher = User::factory()->create();
    User::factory()->create(['name' => 'Verified Vera', 'email' => 'vera@secretdomain.test']);
    User::factory()->unverified()->create(['name' => 'Unverified Uma']);

    $this->actingAs($searcher)->getJson('/reservations/users/search?q=secretdomain')->assertOk()->assertJson([]);
    $this->actingAs($searcher)->getJson('/reservations/users/search?q=Unverified')->assertOk()->assertJson([]);
    $this->actingAs($searcher)->getJson('/reservations/users/search?q=ab')->assertStatus(422);
});

test('the group chat of a cancelled reservation cannot be opened or written to by direct URL', function () {
    $owner = User::factory()->create();
    $reservation = futureReservation($owner, ['status' => 'cancelled']);

    $this->actingAs($owner)->get("/reservations/{$reservation->id}/chat")->assertForbidden();
    $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat", ['body' => 'hello'])->assertForbidden();
    expect(ChatMessage::count())->toBe(0);
});

test('the group chat of a reservation that ended long ago is closed', function () {
    $owner = User::factory()->create();
    $old = makeReservation($owner, now()->subDays(10), now()->subDays(10)->addHour());

    $this->actingAs($owner)->get("/reservations/{$old->id}/chat")->assertForbidden();
    $this->actingAs($owner)->post("/reservations/{$old->id}/chat", ['body' => 'hello'])->assertForbidden();
});

test('a recently ended reservation chat can be read but no longer written to', function () {
    $owner = User::factory()->create();
    $recent = makeReservation($owner, now()->subHours(6), now()->subHours(5));

    $this->actingAs($owner)->get("/reservations/{$recent->id}/chat")->assertOk();
    $this->actingAs($owner)->post("/reservations/{$recent->id}/chat", ['body' => 'late'])->assertSessionHasErrors('body');
    expect(ChatMessage::count())->toBe(0);
});

test('chat access follows the reservation lifecycle, which is also what the websocket channel checks', function () {
    $owner = User::factory()->create();

    expect(futureReservation($owner)->chatIsReadable())->toBeTrue();
    expect(makeReservation($owner, now()->addDays(4), now()->addDays(4)->addHour(), ['status' => 'cancelled'])->chatIsReadable())->toBeFalse();
    expect(makeReservation($owner, now()->addDays(6), now()->addDays(6)->addHour(), ['status' => 'pending'])->chatIsReadable())->toBeFalse();
    expect(makeReservation($owner, now()->subDays(8), now()->subDays(8)->addHour())->chatIsReadable())->toBeFalse();
});

test('reservation chat posting is rate limited', function () {
    $owner = User::factory()->create();
    $reservation = futureReservation($owner);

    foreach (range(1, 20) as $i) {
        $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat", ['body' => "msg {$i}"])->assertRedirect();
    }

    $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat", ['body' => 'one too many'])->assertStatus(429);
});
