<?php

use App\Models\ChatMessage;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Broadcast;

/**
 * Runs the authorisation callback registered for a reservation's chat channel
 * (routes/channels.php), as the broadcaster would for a subscribing browser.
 */
function canListenToReservationChat(User $user, Reservation $reservation): bool
{
    $channels = (new ReflectionProperty(Broadcaster::class, 'channels'))->getValue(Broadcast::driver());
    $callback = $channels['reservation.{reservationId}.chat'];

    return (bool) $callback($user, (string) $reservation->id);
}

test('a chat is open, readable and writable, for an active reservation that has not ended', function () {
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());

    expect($reservation->chatIsReadable())->toBeTrue()
        ->and($reservation->chatIsWritable())->toBeTrue();

    $this->actingAs($owner)->get("/reservations/{$reservation->id}/chat")
        ->assertInertia(fn ($page) => $page->where('readOnly', false)->where('canModerate', true));
});

test('a pending or cancelled reservation has no chat at all', function (string $status) {
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour(), ['status' => $status]);

    $this->actingAs($owner)->get("/reservations/{$reservation->id}/chat")->assertForbidden();
    $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat", ['body' => 'hello'])->assertForbidden();
    expect(canListenToReservationChat($owner, $reservation))->toBeFalse()
        ->and(Reservation::chatGroupsFor($owner))->toHaveCount(0);
})->with(['pending', 'cancelled']);

test('after the reservation ends the chat is read-only for a week', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = makeReservation($owner, now()->subDays(3)->subHour(), now()->subDays(3));
    $reservation->invitations()->attach($friend->id, ['status' => 'accepted']);
    $old = ChatMessage::create(['user_id' => $friend->id, 'reservation_id' => $reservation->id, 'body' => 'good session']);

    foreach ([$owner, $friend] as $user) {
        $this->actingAs($user)->get("/reservations/{$reservation->id}/chat")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('readOnly', true)
                ->where('canModerate', false)
                ->has('messages', 1)
            );
        $this->actingAs($user)->post("/reservations/{$reservation->id}/chat", ['body' => 'one more'])->assertForbidden();
        expect(canListenToReservationChat($user, $reservation))->toBeTrue();
    }

    // Moderating is writing too.
    $this->actingAs($owner)->delete("/reservations/{$reservation->id}/chat/{$old->id}")->assertForbidden();
    $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat/{$old->id}/pin")->assertForbidden();
    $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat/users/{$friend->id}/mute", ['hours' => 1])->assertForbidden();

    expect(ChatMessage::count())->toBe(1)
        ->and($old->fresh()->pinned_at)->toBeNull()
        ->and(Reservation::chatGroupsFor($friend)->pluck('id')->all())->toBe([$reservation->id]);
});

test('a week after the reservation ended the chat is closed, on the page and on the websocket', function () {
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->subDays(8)->subHour(), now()->subDays(8));
    ChatMessage::create(['user_id' => $owner->id, 'reservation_id' => $reservation->id, 'body' => 'ancient']);

    $this->actingAs($owner)->get("/reservations/{$reservation->id}/chat")->assertForbidden();
    $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat", ['body' => 'hello'])->assertForbidden();
    expect(canListenToReservationChat($owner, $reservation))->toBeFalse()
        ->and(Reservation::chatGroupsFor($owner))->toHaveCount(0);
});

test('the websocket channel only lets in the owner and participants who accepted', function () {
    $owner = User::factory()->create();
    [$accepted, $invited, $declined, $outsider] = User::factory()->count(4)->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour(), ['group_size' => 5]);
    $reservation->invitations()->attach($accepted->id, ['status' => 'accepted']);
    $reservation->invitations()->attach($invited->id);
    $reservation->invitations()->attach($declined->id, ['status' => 'declined']);

    expect(canListenToReservationChat($owner, $reservation))->toBeTrue()
        ->and(canListenToReservationChat($accepted, $reservation))->toBeTrue()
        ->and(canListenToReservationChat($invited, $reservation))->toBeFalse()
        ->and(canListenToReservationChat($declined, $reservation))->toBeFalse()
        ->and(canListenToReservationChat($outsider, $reservation))->toBeFalse();
});

test('the chat sidebar only lists chats that can be opened', function () {
    $owner = User::factory()->create();
    $open = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    $over = makeReservation($owner, now()->subDays(2)->subHour(), now()->subDays(2));
    makeReservation($owner, now()->addDays(2), now()->addDays(2)->addHour(), ['status' => 'pending']);
    makeReservation($owner, now()->subDays(20)->subHour(), now()->subDays(20));

    expect(Reservation::chatGroupsFor($owner)->pluck('id')->all())->toBe([$over->id, $open->id]);
});
