<?php

use App\Models\ChatMessage;
use App\Models\User;

test('an invitation takes no seat and gives no access to the group chat', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour(), ['group_size' => 2]);

    $this->actingAs($owner)->post("/reservations/{$reservation->id}/participants", ['user_id' => $friend->id])
        ->assertSessionHas('status', 'participant-invited');

    expect($reservation->participants()->count())->toBe(0)
        ->and($reservation->hasParticipantCapacity())->toBeTrue()
        ->and($reservation->includesParticipant($friend))->toBeFalse();

    $this->actingAs($friend)->get("/reservations/{$reservation->id}/chat")->assertForbidden();
    $this->actingAs($friend)->post("/reservations/{$reservation->id}/chat", ['body' => 'hi'])->assertForbidden();
    expect(ChatMessage::count())->toBe(0);
});

test('an invited person sees the invitation, with who sent it', function () {
    $owner = User::factory()->create(['name' => 'Olivia Owner']);
    $friend = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    $reservation->invitations()->attach($friend->id);

    $this->actingAs($friend)->get('/reservations')->assertInertia(fn ($page) => $page
        ->has('invitations', 1)
        ->where('invitations.0.id', $reservation->id)
        ->where('invitations.0.owner_name', 'Olivia Owner')
        ->missing('invitations.0.user_id')
        ->has('mine', 0)
    );
});

test('accepting an invitation makes someone a participant with chat access', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    $reservation->invitations()->attach($friend->id);

    $this->actingAs($friend)->post("/reservations/{$reservation->id}/invitation/accept")
        ->assertRedirect(route('reservations.index'))
        ->assertSessionHas('status', 'invitation-accepted');

    $invitation = $reservation->invitationFor($friend);
    expect($invitation->status)->toBe('accepted')
        ->and($invitation->responded_at)->not->toBeNull()
        ->and($reservation->includesParticipant($friend))->toBeTrue();

    $this->actingAs($friend)->get("/reservations/{$reservation->id}/chat")->assertOk();
    $this->actingAs($friend)->post("/reservations/{$reservation->id}/chat", ['body' => 'in!'])->assertRedirect();
    $this->actingAs($friend)->get('/reservations')->assertInertia(fn ($page) => $page
        ->has('invitations', 0)
        ->has('mine', 1)
    );
});

test('accepting is idempotent', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    $reservation->invitations()->attach($friend->id, ['status' => 'accepted']);

    $this->actingAs($friend)->post("/reservations/{$reservation->id}/invitation/accept")
        ->assertSessionHas('status', 'invitation-accepted');

    expect($reservation->participants()->count())->toBe(1);
});

test('an invitation cannot be accepted once the paid group is full', function () {
    $owner = User::factory()->create();
    $late = User::factory()->create();
    // group_size 3 = the owner plus two guests.
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour(), ['group_size' => 3]);
    $reservation->invitations()->attach($late->id);
    $reservation->invitations()->attach(User::factory()->count(2)->create()->pluck('id'), ['status' => 'accepted']);

    $this->actingAs($late)->post("/reservations/{$reservation->id}/invitation/accept")
        ->assertSessionHas('status', 'invitation-full');

    expect($reservation->invitationFor($late)->status)->toBe('invited')
        ->and($reservation->participants()->count())->toBe(2);
});

test('an invitation cannot be accepted for a reservation that is cancelled or over', function (string $state) {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = match ($state) {
        'cancelled' => makeReservation($owner, now()->addDay(), now()->addDay()->addHour(), ['status' => 'cancelled']),
        'over' => makeReservation($owner, now()->subHours(3), now()->subHour()),
    };
    $reservation->invitations()->attach($friend->id);

    $this->actingAs($friend)->post("/reservations/{$reservation->id}/invitation/accept")
        ->assertSessionHas('status', 'invitation-unavailable');

    expect($reservation->invitationFor($friend)->status)->toBe('invited');
    $this->actingAs($friend)->get('/reservations')->assertInertia(fn ($page) => $page->has('invitations', 0));
})->with(['cancelled', 'over']);

test('someone who was never invited cannot join by accepting', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());

    $this->actingAs($stranger)->post("/reservations/{$reservation->id}/invitation/accept")
        ->assertSessionHas('status', 'invitation-unavailable');

    expect($reservation->invitations()->count())->toBe(0);
});

test('declining an invitation keeps the person out, and the owner cannot invite them again', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    $reservation->invitations()->attach($friend->id);

    $this->actingAs($friend)->post("/reservations/{$reservation->id}/invitation/decline")
        ->assertSessionHas('status', 'invitation-declined');

    expect($reservation->invitationFor($friend)->status)->toBe('declined')
        ->and($reservation->includesParticipant($friend))->toBeFalse();

    $this->actingAs($friend)->get("/reservations/{$reservation->id}/chat")->assertForbidden();
    $this->actingAs($friend)->get('/reservations')->assertInertia(fn ($page) => $page->has('invitations', 0));

    $this->actingAs($owner)->post("/reservations/{$reservation->id}/participants", ['user_id' => $friend->id])
        ->assertSessionHasErrors('user_id');
    $this->actingAs($owner)->delete("/reservations/{$reservation->id}/participants/{$friend->id}");
    $this->actingAs($owner)->post("/reservations/{$reservation->id}/participants", ['user_id' => $friend->id])
        ->assertSessionHasErrors('user_id');
    expect($reservation->invitationFor($friend)->status)->toBe('declined');
});

test('someone who already accepted cannot decline their way out, they leave', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    $reservation->invitations()->attach($friend->id, ['status' => 'accepted']);

    $this->actingAs($friend)->post("/reservations/{$reservation->id}/invitation/decline")
        ->assertSessionHas('status', 'invitation-unavailable');

    expect($reservation->invitationFor($friend)->status)->toBe('accepted');
});

test('declining without an invitation is a 404', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());

    $this->actingAs($stranger)->post("/reservations/{$reservation->id}/invitation/decline")->assertNotFound();
});

test('inviting someone twice is harmless', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());

    $this->actingAs($owner)->post("/reservations/{$reservation->id}/participants", ['user_id' => $friend->id]);
    $this->actingAs($owner)->post("/reservations/{$reservation->id}/participants", ['user_id' => $friend->id])
        ->assertSessionHasNoErrors();

    expect($reservation->invitations()->count())->toBe(1);
});

test('the owner can withdraw an invitation that has not been answered', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    $reservation->invitations()->attach($friend->id);

    $this->actingAs($owner)->delete("/reservations/{$reservation->id}/participants/{$friend->id}");

    expect($reservation->invitations()->count())->toBe(0);
});

test('only an active reservation that has not ended can invite anyone', function (string $state) {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = match ($state) {
        'pending' => makeReservation($owner, now()->addDay(), now()->addDay()->addHour(), ['status' => 'pending']),
        'cancelled' => makeReservation($owner, now()->addDay(), now()->addDay()->addHour(), ['status' => 'cancelled']),
        'over' => makeReservation($owner, now()->subHours(3), now()->subHour()),
    };

    $this->actingAs($owner)->post("/reservations/{$reservation->id}/participants", ['user_id' => $friend->id])
        ->assertStatus(422);

    expect($reservation->invitations()->count())->toBe(0);
})->with(['pending', 'cancelled', 'over']);

test('an unverified customer cannot be invited', function () {
    $owner = User::factory()->create();
    $unverified = User::factory()->unverified()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());

    $this->actingAs($owner)->post("/reservations/{$reservation->id}/participants", ['user_id' => $unverified->id])
        ->assertSessionHasErrors('user_id');

    expect($reservation->invitations()->count())->toBe(0);
});

test('an invitation does not count towards the group size when inviting, only accepted ones do', function () {
    $owner = User::factory()->create();
    // group_size 2 = owner + one guest.
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour(), ['group_size' => 2]);
    [$first, $second] = User::factory()->count(2)->create();

    $this->actingAs($owner)->post("/reservations/{$reservation->id}/participants", ['user_id' => $first->id])
        ->assertSessionHasNoErrors();
    $this->actingAs($owner)->post("/reservations/{$reservation->id}/participants", ['user_id' => $second->id])
        ->assertSessionHasNoErrors();

    $this->actingAs($first)->post("/reservations/{$reservation->id}/invitation/accept")
        ->assertSessionHas('status', 'invitation-accepted');
    $this->actingAs($second)->post("/reservations/{$reservation->id}/invitation/accept")
        ->assertSessionHas('status', 'invitation-full');

    expect($reservation->participants()->pluck('users.id')->all())->toBe([$first->id]);

    // And with the group full, nobody new can be invited either.
    $third = User::factory()->create();
    $this->actingAs($owner)->post("/reservations/{$reservation->id}/participants", ['user_id' => $third->id])
        ->assertSessionHasErrors('user_id');
});

test('the owner sees who is invited or declined, other participants only see who joined', function () {
    $owner = User::factory()->create();
    [$joined, $invited, $declined] = User::factory()->count(3)->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour(), ['group_size' => 5]);
    $reservation->invitations()->attach($joined->id, ['status' => 'accepted']);
    $reservation->invitations()->attach($invited->id);
    $reservation->invitations()->attach($declined->id, ['status' => 'declined']);

    $this->actingAs($owner)->get('/reservations')->assertInertia(fn ($page) => $page
        ->has('mine.0.participants', 3)
        ->where('mine.0.participants.0.status', fn ($status) => in_array($status, ['accepted', 'invited', 'declined'], true))
        ->missing('mine.0.participants.0.email')
    );

    $this->actingAs($joined)->get('/reservations')->assertInertia(fn ($page) => $page
        ->has('mine.0.participants', 1)
        ->where('mine.0.participants.0.id', $joined->id)
    );
});

test('an invited person does not get in with the group while the reservation is running', function () {
    $owner = User::factory()->create();
    $invited = User::factory()->create();
    $reservation = makeReservation($owner, now()->subMinutes(10), now()->addHour());
    $reservation->invitations()->attach($invited->id);

    expect($reservation->includesParticipant($invited))->toBeFalse();
});
