<?php

use App\Events\ChatMessageSent;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Event;

test('the reservation owner can view and post in their own group chat', function () {
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());

    $response = $this->actingAs($owner)->get("/reservations/{$reservation->id}/chat");
    $response->assertOk();

    $postResponse = $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat", ['body' => 'see you all there']);
    $postResponse->assertRedirect();

    expect(ChatMessage::where('reservation_id', $reservation->id)->where('body', 'see you all there')->exists())->toBeTrue();
});

test('a named participant can view and post in the group chat', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    $reservation->participants()->attach($friend->id);

    $response = $this->actingAs($friend)->get("/reservations/{$reservation->id}/chat");
    $response->assertOk();

    $postResponse = $this->actingAs($friend)->post("/reservations/{$reservation->id}/chat", ['body' => 'excited!']);
    $postResponse->assertRedirect();
});

test('someone outside the reservation cannot view or post in its group chat', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());

    $this->actingAs($outsider)->get("/reservations/{$reservation->id}/chat")->assertForbidden();
    $this->actingAs($outsider)->post("/reservations/{$reservation->id}/chat", ['body' => 'sneaking in'])->assertForbidden();

    expect(ChatMessage::where('reservation_id', $reservation->id)->count())->toBe(0);
});

test('reservation group chat messages do not appear in the global chat feed', function () {
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    ChatMessage::create(['user_id' => $owner->id, 'reservation_id' => $reservation->id, 'body' => 'group only']);
    ChatMessage::create(['user_id' => $owner->id, 'body' => 'global room']);

    $response = $this->actingAs($owner)->get('/chat');

    $response->assertInertia(fn ($page) => $page
        ->has('messages', 1)
        ->where('messages.0.body', 'global room')
    );
});

test('a reply within a reservation chat carries a quote of the original', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    $reservation->participants()->attach($friend->id);
    $original = ChatMessage::create(['user_id' => $owner->id, 'reservation_id' => $reservation->id, 'body' => 'bring your own pads']);

    $this->actingAs($friend)->post("/reservations/{$reservation->id}/chat", [
        'body' => 'got it, thanks',
        'reply_to_message_id' => $original->id,
    ])->assertRedirect();

    $reply = ChatMessage::where('body', 'got it, thanks')->sole();
    expect($reply->reply_to_message_id)->toBe($original->id);
});

test('a reply cannot point at a message from a different reservation or the global chat', function () {
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    $otherReservation = makeReservation($owner, now()->addDays(2), now()->addDays(2)->addHour());
    $messageElsewhere = ChatMessage::create(['user_id' => $owner->id, 'reservation_id' => $otherReservation->id, 'body' => 'wrong room']);
    $globalMessage = ChatMessage::create(['user_id' => $owner->id, 'body' => 'also wrong room']);

    foreach ([$messageElsewhere->id, $globalMessage->id] as $targetId) {
        $response = $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat", [
            'body' => 'sneaky reply',
            'reply_to_message_id' => $targetId,
        ]);

        $response->assertSessionHasErrors('reply_to_message_id');
    }

    expect(ChatMessage::where('body', 'sneaky reply')->exists())->toBeFalse();
});

test('a group chat message broadcasts on the reservation-specific private channel', function () {
    Event::fake([ChatMessageSent::class]);

    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());

    $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat", ['body' => 'hello group']);

    Event::assertDispatched(ChatMessageSent::class, function (ChatMessageSent $event) use ($reservation) {
        $channels = $event->broadcastOn();

        return $channels[0]->name === "private-reservation.{$reservation->id}.chat";
    });
});
