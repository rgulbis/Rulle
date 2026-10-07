<?php

use App\Events\ChatMessagePinChanged;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/*
| Reservation chat moderation (single source of truth:
| App\Support\ReservationChatModeration) is ownership-based, not rank-based
| like the global room — everyone here is a customer:
|
|   owner        – may delete / pin any message in their own reservation's
|                  chat, and mute / unmute any of their reservation's
|                  participants. Never themselves.
|   participant  – no moderation rights, even in their own reservation.
|
| A reservation's own moderation must never reach the global chat, another
| reservation, or the owner themselves.
*/

test('the owner can delete a participant message but not their own', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    $reservation->invitations()->attach($friend->id, ['status' => 'accepted']);

    $friendMessage = ChatMessage::create(['user_id' => $friend->id, 'reservation_id' => $reservation->id, 'body' => 'from friend']);
    $ownMessage = ChatMessage::create(['user_id' => $owner->id, 'reservation_id' => $reservation->id, 'body' => 'from owner']);

    $this->actingAs($owner)->delete("/reservations/{$reservation->id}/chat/{$ownMessage->id}")->assertForbidden();
    expect(ChatMessage::find($ownMessage->id))->not->toBeNull();

    $this->actingAs($owner)->delete("/reservations/{$reservation->id}/chat/{$friendMessage->id}")->assertRedirect();
    expect(ChatMessage::find($friendMessage->id))->toBeNull();
});

test('a participant has no moderation rights in their own reservation chat', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    $reservation->invitations()->attach($friend->id, ['status' => 'accepted']);

    $message = ChatMessage::create(['user_id' => $owner->id, 'reservation_id' => $reservation->id, 'body' => 'from owner']);

    $this->actingAs($friend)->delete("/reservations/{$reservation->id}/chat/{$message->id}")->assertForbidden();
    $this->actingAs($friend)->post("/reservations/{$reservation->id}/chat/{$message->id}/pin")->assertForbidden();
    $this->actingAs($friend)->post("/reservations/{$reservation->id}/chat/users/{$owner->id}/mute", ['hours' => 1])->assertForbidden();

    expect(ChatMessage::find($message->id))->not->toBeNull();
    expect($message->fresh()->pinned_at)->toBeNull();
});

test('the owner of a different reservation cannot moderate this one', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    makeReservation($otherOwner, now()->addDays(3), now()->addDays(3)->addHour());

    $message = ChatMessage::create(['user_id' => $owner->id, 'reservation_id' => $reservation->id, 'body' => 'hi']);

    $this->actingAs($otherOwner)->delete("/reservations/{$reservation->id}/chat/{$message->id}")->assertForbidden();
    $this->actingAs($otherOwner)->post("/reservations/{$reservation->id}/chat/{$message->id}/pin")->assertForbidden();
    $this->actingAs($otherOwner)->post("/reservations/{$reservation->id}/chat/users/{$owner->id}/mute", ['hours' => 1])->assertForbidden();
});

test('the owner can pin and unpin any message, including their own', function () {
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    $message = ChatMessage::create(['user_id' => $owner->id, 'reservation_id' => $reservation->id, 'body' => 'pin me']);

    $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat/{$message->id}/pin")->assertRedirect();
    expect($message->fresh()->pinned_at)->not->toBeNull();

    $this->actingAs($owner)->delete("/reservations/{$reservation->id}/chat/{$message->id}/pin")->assertRedirect();
    expect($message->fresh()->pinned_at)->toBeNull();
});

test('a pin change on a reservation message broadcasts on that reservation channel, not the global one', function () {
    Event::fake([ChatMessagePinChanged::class]);

    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    $message = ChatMessage::create(['user_id' => $owner->id, 'reservation_id' => $reservation->id, 'body' => 'pin me']);

    $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat/{$message->id}/pin");

    Event::assertDispatched(ChatMessagePinChanged::class, function (ChatMessagePinChanged $event) use ($reservation) {
        return $event->broadcastOn()[0]->name === "private-reservation.{$reservation->id}.chat";
    });
});

test('the owner cannot mute or delete-target themselves', function () {
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());

    $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat/users/{$owner->id}/mute", ['hours' => 1])->assertForbidden();

    expect($owner->fresh()->isChatMuted())->toBeFalse();
});

test('the owner cannot mute someone who is not a participant of this reservation', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());

    $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat/users/{$outsider->id}/mute", ['hours' => 1])->assertForbidden();
});

test('muting a participant blocks them from posting in this reservation chat only', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    $reservation->invitations()->attach($friend->id, ['status' => 'accepted']);

    $otherReservation = makeReservation($owner, now()->addDay()->addDays(2), now()->addDay()->addDays(2)->addHour());
    $otherReservation->invitations()->attach($friend->id, ['status' => 'accepted']);

    $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat/users/{$friend->id}/mute", ['hours' => 1])->assertRedirect();

    // Muted here...
    $this->actingAs($friend)->post("/reservations/{$reservation->id}/chat", ['body' => 'let me in'])->assertSessionHasErrors('body');

    // ...but nowhere else: not this friend's other reservation, and not the
    // global room (chat_muted_until on User is untouched by this).
    $this->actingAs($friend)->post("/reservations/{$otherReservation->id}/chat", ['body' => 'still fine here'])->assertRedirect();
    $this->actingAs($friend)->post('/chat', ['body' => 'still fine globally'])->assertRedirect();

    expect($friend->fresh()->isChatMuted())->toBeFalse();
});

test('unmuting a participant restores their ability to post', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    $reservation->invitations()->attach($friend->id, ['status' => 'accepted', 'chat_muted_until' => now()->addHour()]);

    $this->actingAs($friend)->post("/reservations/{$reservation->id}/chat", ['body' => 'still muted'])->assertSessionHasErrors('body');

    $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat/users/{$friend->id}/unmute")->assertRedirect();

    $this->actingAs($friend)->post("/reservations/{$reservation->id}/chat", ['body' => 'back in'])->assertRedirect();
});

test('cross-reservation message ids 404 instead of leaking a 403', function () {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    $reservationA = makeReservation($ownerA, now()->addDay(), now()->addDay()->addHour());
    $reservationB = makeReservation($ownerB, now()->addDays(3), now()->addDays(3)->addHour());
    $messageA = ChatMessage::create(['user_id' => $ownerA->id, 'reservation_id' => $reservationA->id, 'body' => 'in A']);

    $this->actingAs($ownerB)->delete("/reservations/{$reservationB->id}/chat/{$messageA->id}")->assertNotFound();
    $this->actingAs($ownerB)->post("/reservations/{$reservationB->id}/chat/{$messageA->id}/pin")->assertNotFound();
});

test('the owner gets the list of currently muted participants of this reservation only', function () {
    $owner = User::factory()->create();
    $muted = User::factory()->create();
    $expired = User::factory()->create();
    $fine = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour(), ['group_size' => 4]);
    $reservation->invitations()->attach($muted->id, ['status' => 'accepted', 'chat_muted_until' => now()->addHour()]);
    $reservation->invitations()->attach($expired->id, ['status' => 'accepted', 'chat_muted_until' => now()->subHour()]);
    $reservation->invitations()->attach($fine->id, ['status' => 'accepted']);

    // Muted in a different reservation — must not show up here.
    $elsewhere = makeReservation($owner, now()->addDays(2), now()->addDays(2)->addHour());
    $elsewhere->invitations()->attach($fine->id, ['status' => 'accepted', 'chat_muted_until' => now()->addHour()]);

    $this->actingAs($owner)->get("/reservations/{$reservation->id}/chat")->assertInertia(fn ($page) => $page
        ->has('mutedParticipants', 1)
        ->where('mutedParticipants.0.id', $muted->id)
        ->where('mutedParticipants.0.name', $muted->name)
        ->has('mutedParticipants.0.chat_muted_until')
    );
});

test('a participant never receives the muted participants list', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $muted = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour(), ['group_size' => 3]);
    $reservation->invitations()->attach($friend->id, ['status' => 'accepted']);
    $reservation->invitations()->attach($muted->id, ['status' => 'accepted', 'chat_muted_until' => now()->addHour()]);

    $this->actingAs($friend)->get("/reservations/{$reservation->id}/chat")->assertInertia(fn ($page) => $page->has('mutedParticipants', 0));
});
