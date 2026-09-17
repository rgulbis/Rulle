<?php

use App\Models\ChatMessage;
use App\Models\User;

test('a logged-in customer can view the chat page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/chat');

    $response->assertOk();
});

test('a user with an unverified email cannot access chat', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get('/chat');

    $response->assertRedirect(route('verification.notice'));
});

test('the chat page does not leak the raw user_id column', function () {
    $author = User::factory()->create();
    ChatMessage::create(['user_id' => $author->id, 'body' => 'hello']);

    $viewer = User::factory()->create();
    $response = $this->actingAs($viewer)->get('/chat');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('messages', 1)
        ->where('messages.0.user.id', $author->id)
        ->missing('messages.0.user_id')
        ->missing('messages.0.updated_at')
    );
});

test('a customer can post a chat message', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/chat', ['body' => 'Hello park!']);

    $response->assertRedirect();
    expect(ChatMessage::where('user_id', $user->id)->where('body', 'Hello park!')->exists())->toBeTrue();
});

test('an empty message is rejected', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/chat', ['body' => '']);

    $response->assertSessionHasErrors('body');
    expect(ChatMessage::count())->toBe(0);
});

test('a message over the length limit is rejected', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/chat', ['body' => str_repeat('a', 501)]);

    $response->assertSessionHasErrors('body');
    expect(ChatMessage::count())->toBe(0);
});

test('a message right at the length limit is accepted', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/chat', ['body' => str_repeat('a', 500)]);

    $response->assertRedirect();
    expect(ChatMessage::count())->toBe(1);
});

test('staff and admins can post too', function () {
    $employee = User::factory()->create(['role' => 'employee']);

    $response = $this->actingAs($employee)->post('/chat', ['body' => 'Park closes at 23:00 tonight.']);

    $response->assertRedirect();
    expect(ChatMessage::count())->toBe(1);
});

test('a muted user cannot post a message', function () {
    $user = User::factory()->create(['chat_muted_until' => now()->addHour()]);

    $response = $this->actingAs($user)->post('/chat', ['body' => 'still trying to post']);

    $response->assertForbidden();
    expect(ChatMessage::count())->toBe(0);
});

test('a mute that has already expired no longer blocks posting', function () {
    $user = User::factory()->create(['chat_muted_until' => now()->subMinute()]);

    $response = $this->actingAs($user)->post('/chat', ['body' => 'back now']);

    $response->assertRedirect();
    expect(ChatMessage::count())->toBe(1);
});

test('an employee can delete any chat message', function () {
    $author = User::factory()->create();
    $message = ChatMessage::create(['user_id' => $author->id, 'body' => 'rude thing']);
    $employee = User::factory()->create(['role' => 'employee']);

    $response = $this->actingAs($employee)->delete("/chat/{$message->id}");

    $response->assertRedirect();
    expect(ChatMessage::find($message->id))->toBeNull();
});

test('a customer cannot delete a chat message', function () {
    $author = User::factory()->create();
    $message = ChatMessage::create(['user_id' => $author->id, 'body' => 'hello']);
    $someoneElse = User::factory()->create();

    $response = $this->actingAs($someoneElse)->delete("/chat/{$message->id}");

    $response->assertForbidden();
    expect(ChatMessage::find($message->id))->not->toBeNull();
});

test('an employee can mute a customer, which then blocks them from posting', function () {
    $employee = User::factory()->create(['role' => 'employee']);
    $troublemaker = User::factory()->create();

    $response = $this->actingAs($employee)->post("/chat/users/{$troublemaker->id}/mute", ['hours' => 24]);

    $response->assertRedirect();
    expect($troublemaker->fresh()->isChatMuted())->toBeTrue();

    $postResponse = $this->actingAs($troublemaker->fresh())->post('/chat', ['body' => 'let me in']);
    $postResponse->assertForbidden();
});

test('an employee cannot mute themselves', function () {
    $employee = User::factory()->create(['role' => 'employee']);

    $response = $this->actingAs($employee)->post("/chat/users/{$employee->id}/mute", ['hours' => 24]);

    $response->assertStatus(422);
    expect($employee->fresh()->isChatMuted())->toBeFalse();
});

test('a customer cannot mute anyone', function () {
    $customer = User::factory()->create();
    $target = User::factory()->create();

    $response = $this->actingAs($customer)->post("/chat/users/{$target->id}/mute", ['hours' => 24]);

    $response->assertForbidden();
    expect($target->fresh()->isChatMuted())->toBeFalse();
});

test('an employee can unmute a customer', function () {
    $employee = User::factory()->create(['role' => 'employee']);
    $muted = User::factory()->create(['chat_muted_until' => now()->addDay()]);

    $response = $this->actingAs($employee)->post("/chat/users/{$muted->id}/unmute");

    $response->assertRedirect();
    expect($muted->fresh()->isChatMuted())->toBeFalse();
});
