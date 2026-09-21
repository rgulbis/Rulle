<?php

use App\Events\ChatMessagePinChanged;
use App\Events\ChatSlowModeActivated;
use App\Models\ChatMessage;
use App\Models\User;
use App\Support\ChatSlowMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

function fillChatWithMessages(int $count): void
{
    $author = User::factory()->create();

    foreach (range(1, $count) as $i) {
        ChatMessage::create(['user_id' => $author->id, 'body' => "message {$i}"]);
    }
}

// --- Pinning ---------------------------------------------------------------

test('an employee can pin and unpin a message, and pins show up on the chat page', function () {
    $employee = User::factory()->create(['role' => 'employee']);
    $author = User::factory()->create();
    $message = ChatMessage::create(['user_id' => $author->id, 'body' => 'park rules']);

    $this->actingAs($employee)->post("/chat/{$message->id}/pin")->assertRedirect();
    expect($message->fresh()->pinned_at)->not->toBeNull();

    $this->actingAs($employee)->get('/chat')->assertInertia(fn ($page) => $page
        ->has('pinned', 1)
        ->where('pinned.0.body', 'park rules')
        ->where('pinned.0.pinned', true)
    );

    $this->actingAs($employee)->delete("/chat/{$message->id}/pin")->assertRedirect();
    expect($message->fresh()->pinned_at)->toBeNull();

    $this->actingAs($employee)->get('/chat')->assertInertia(fn ($page) => $page->has('pinned', 0));
});

test('a customer cannot pin or unpin a message', function () {
    $customer = User::factory()->create();
    $message = ChatMessage::create(['user_id' => $customer->id, 'body' => 'pin me']);

    $this->actingAs($customer)->post("/chat/{$message->id}/pin")->assertForbidden();
    expect($message->fresh()->pinned_at)->toBeNull();

    $message->pin();
    $this->actingAs($customer)->delete("/chat/{$message->id}/pin")->assertForbidden();
    expect($message->fresh()->pinned_at)->not->toBeNull();
});

test('a reservation group chat message cannot be pinned', function () {
    $employee = User::factory()->create(['role' => 'employee']);
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    $message = ChatMessage::create(['user_id' => $owner->id, 'reservation_id' => $reservation->id, 'body' => 'private']);

    $this->actingAs($employee)->post("/chat/{$message->id}/pin")->assertNotFound();
    expect($message->fresh()->pinned_at)->toBeNull();
});

test('pinning broadcasts the change so open chats update live', function () {
    Event::fake([ChatMessagePinChanged::class]);

    $employee = User::factory()->create(['role' => 'employee']);
    $message = ChatMessage::create(['user_id' => $employee->id, 'body' => 'announcement']);

    $this->actingAs($employee)->post("/chat/{$message->id}/pin");

    Event::assertDispatched(ChatMessagePinChanged::class, fn (ChatMessagePinChanged $event) => $event->broadcastWith()['pinned'] === true
        && $event->broadcastWith()['message']['id'] === $message->id);
});

// --- Slow mode -------------------------------------------------------------

test('slow mode stays off while the room is quiet', function () {
    fillChatWithMessages(9);

    expect(ChatSlowMode::isActive())->toBeFalse();
});

test('slow mode switches on by itself when the room gets busy, and announces it', function () {
    Event::fake([ChatSlowModeActivated::class]);

    fillChatWithMessages(10);

    expect(ChatSlowMode::isActive())->toBeTrue();
    Event::assertDispatchedTimes(ChatSlowModeActivated::class, 1);
});

test('an old burst of messages does not trigger slow mode', function () {
    $author = User::factory()->create();

    foreach (range(1, 10) as $i) {
        ChatMessage::create(['user_id' => $author->id, 'body' => "old {$i}"]);
    }
    // A fresh room later on: the old burst is outside the trigger window.
    Cache::flush();
    $this->travel(5)->minutes();

    ChatMessage::create(['user_id' => $author->id, 'body' => 'just one now']);

    expect(ChatSlowMode::isActive())->toBeFalse();
});

test('during slow mode a customer has to wait between messages, then can post again', function () {
    fillChatWithMessages(10);
    $customer = User::factory()->create();

    $this->actingAs($customer)->post('/chat', ['body' => 'first'])->assertRedirect();
    expect(ChatMessage::where('body', 'first')->exists())->toBeTrue();

    $blocked = $this->actingAs($customer)->post('/chat', ['body' => 'too soon']);
    $blocked->assertSessionHasErrors('body');
    expect(ChatMessage::where('body', 'too soon')->exists())->toBeFalse();

    $this->travel(11)->seconds();

    $this->actingAs($customer)->post('/chat', ['body' => 'after the wait'])->assertRedirect();
    expect(ChatMessage::where('body', 'after the wait')->exists())->toBeTrue();
});

test('employees and admins are never slowed down', function () {
    fillChatWithMessages(10);

    foreach (['employee', 'admin'] as $role) {
        $staff = User::factory()->create(['role' => $role]);

        $this->actingAs($staff)->post('/chat', ['body' => "{$role} one"])->assertRedirect();
        $this->actingAs($staff)->post('/chat', ['body' => "{$role} two"])->assertRedirect();

        expect(ChatMessage::where('body', "{$role} two")->exists())->toBeTrue();
    }
});

test('slow mode ends by itself after its duration', function () {
    fillChatWithMessages(10);
    expect(ChatSlowMode::isActive())->toBeTrue();

    $this->travel(3)->minutes();

    expect(ChatSlowMode::isActive())->toBeFalse();
});

test('slow mode does not apply inside reservation group chats', function () {
    fillChatWithMessages(10);
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());

    $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat", ['body' => 'one'])->assertRedirect();
    $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat", ['body' => 'two'])->assertRedirect();

    expect(ChatMessage::where('reservation_id', $reservation->id)->count())->toBe(2);
});

test('the chat page tells the client whether slow mode is active', function () {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)->get('/chat')->assertInertia(fn ($page) => $page
        ->where('slowMode.remaining_seconds', 0)
        ->where('slowMode.cooldown_seconds', 10)
    );

    fillChatWithMessages(10);

    $this->actingAs($viewer)->get('/chat')->assertInertia(fn ($page) => $page
        ->where('slowMode.remaining_seconds', fn ($seconds) => $seconds > 0)
    );
});
