<?php

use App\Events\ChatMessageDeleted;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/*
| Moderation hierarchy (single source of truth: App\Support\ChatModeration):
|
|   customer  – no moderation rights
|   employee  – may delete / mute / unmute customers; may pin any global message
|   admin     – (Filament only) may also mute / unmute employees; nobody mutes an admin
|
| Every global-chat endpoint must also be unable to touch a reservation's
| private group chat, whatever message id it is given.
*/

function privateGroupMessage(): ChatMessage
{
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());

    return ChatMessage::create(['user_id' => $owner->id, 'reservation_id' => $reservation->id, 'body' => 'private group talk']);
}

// --- Global endpoints can never reach a private reservation chat -----------

test('an employee cannot delete a reservation group chat message through the global delete route', function () {
    $employee = User::factory()->create(['role' => 'employee']);
    $message = privateGroupMessage();

    Event::fake([ChatMessageDeleted::class]);

    $this->actingAs($employee)->delete("/chat/{$message->id}")->assertNotFound();

    expect(ChatMessage::find($message->id))->not->toBeNull();
    Event::assertNotDispatched(ChatMessageDeleted::class);
});

test('an employee cannot pin or unpin a reservation group chat message', function () {
    $employee = User::factory()->create(['role' => 'employee']);
    $message = privateGroupMessage();

    $this->actingAs($employee)->post("/chat/{$message->id}/pin")->assertNotFound();
    $this->actingAs($employee)->delete("/chat/{$message->id}/pin")->assertNotFound();

    expect($message->fresh()->pinned_at)->toBeNull();
});

test('muting someone only affects the global room, not their reservation group chat', function () {
    $owner = User::factory()->create(['chat_muted_until' => now()->addDay()]);
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());

    $this->actingAs($owner)->post('/chat', ['body' => 'global'])->assertForbidden();
    $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat", ['body' => 'private'])->assertRedirect();

    expect(ChatMessage::where('reservation_id', $reservation->id)->count())->toBe(1);
});

// --- Moderator hierarchy ----------------------------------------------------

test('an employee can delete a customer message but not an employee or admin message', function () {
    $employee = User::factory()->create(['role' => 'employee']);

    $customerMessage = ChatMessage::create(['user_id' => User::factory()->create()->id, 'body' => 'customer']);
    $peerMessage = ChatMessage::create(['user_id' => User::factory()->create(['role' => 'employee'])->id, 'body' => 'peer']);
    $adminMessage = ChatMessage::create(['user_id' => User::factory()->create(['role' => 'admin'])->id, 'body' => 'admin']);

    $this->actingAs($employee)->delete("/chat/{$peerMessage->id}")->assertForbidden();
    $this->actingAs($employee)->delete("/chat/{$adminMessage->id}")->assertForbidden();
    expect(ChatMessage::find($peerMessage->id))->not->toBeNull();
    expect(ChatMessage::find($adminMessage->id))->not->toBeNull();

    $this->actingAs($employee)->delete("/chat/{$customerMessage->id}")->assertRedirect();
    expect(ChatMessage::find($customerMessage->id))->toBeNull();
});

test('an employee can mute a customer but not another employee or an admin', function () {
    $employee = User::factory()->create(['role' => 'employee']);
    $peer = User::factory()->create(['role' => 'employee']);
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = User::factory()->create();

    $this->actingAs($employee)->post("/chat/users/{$peer->id}/mute", ['hours' => 1])->assertForbidden();
    $this->actingAs($employee)->post("/chat/users/{$admin->id}/mute", ['hours' => 1])->assertForbidden();
    $this->actingAs($employee)->post("/chat/users/{$employee->id}/mute", ['hours' => 1])->assertStatus(422);

    expect($peer->fresh()->isChatMuted())->toBeFalse();
    expect($admin->fresh()->isChatMuted())->toBeFalse();
    expect($employee->fresh()->isChatMuted())->toBeFalse();

    $this->actingAs($employee)->post("/chat/users/{$customer->id}/mute", ['hours' => 1])->assertRedirect();
    expect($customer->fresh()->isChatMuted())->toBeTrue();
});

test('an employee cannot unmute another employee or an admin', function () {
    $employee = User::factory()->create(['role' => 'employee']);
    $mutedPeer = User::factory()->create(['role' => 'employee', 'chat_muted_until' => now()->addDay()]);
    $mutedAdmin = User::factory()->create(['role' => 'admin', 'chat_muted_until' => now()->addDay()]);

    $this->actingAs($employee)->post("/chat/users/{$mutedPeer->id}/unmute")->assertForbidden();
    $this->actingAs($employee)->post("/chat/users/{$mutedAdmin->id}/unmute")->assertForbidden();

    expect($mutedPeer->fresh()->isChatMuted())->toBeTrue();
    expect($mutedAdmin->fresh()->isChatMuted())->toBeTrue();
});

test('an employee can pin any global message, including a staff announcement', function () {
    $employee = User::factory()->create(['role' => 'employee']);
    $adminMessage = ChatMessage::create(['user_id' => User::factory()->create(['role' => 'admin'])->id, 'body' => 'park rules']);

    $this->actingAs($employee)->post("/chat/{$adminMessage->id}/pin")->assertRedirect();

    expect($adminMessage->fresh()->pinned_at)->not->toBeNull();
});

test('customers cannot use any moderation route', function () {
    $customer = User::factory()->create();
    $other = User::factory()->create();
    $message = ChatMessage::create(['user_id' => $other->id, 'body' => 'hi']);

    $this->actingAs($customer)->delete("/chat/{$message->id}")->assertForbidden();
    $this->actingAs($customer)->post("/chat/{$message->id}/pin")->assertForbidden();
    $this->actingAs($customer)->post("/chat/users/{$other->id}/mute", ['hours' => 1])->assertForbidden();
    $this->actingAs($customer)->post("/chat/users/{$other->id}/unmute")->assertForbidden();
});

// --- Admin moderation in Filament goes through the same hierarchy ----------

use App\Filament\Resources\ChatMessages\ChatMessageResource;
use App\Filament\Resources\ChatMessages\Pages\ListChatMessages;
use Livewire\Livewire;

test('the admin chat list only ever contains global room messages', function () {
    $private = privateGroupMessage();
    $global = ChatMessage::create(['user_id' => User::factory()->create()->id, 'body' => 'public']);

    $ids = ChatMessageResource::getEloquentQuery()->pluck('id');

    expect($ids)->toContain($global->id)->not->toContain($private->id);
});

test('an admin can mute a customer and an employee, but the mute action is hidden for another admin', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin);

    $customer = User::factory()->create();
    $employee = User::factory()->create(['role' => 'employee']);
    $otherAdmin = User::factory()->create(['role' => 'admin']);

    foreach ([$customer, $employee] as $target) {
        $message = ChatMessage::create(['user_id' => $target->id, 'body' => "from {$target->role}"]);

        Livewire::test(ListChatMessages::class)
            ->callTableAction('mute', $message, ['hours' => 24]);
    }

    $adminMessage = ChatMessage::create(['user_id' => $otherAdmin->id, 'body' => 'from admin']);
    Livewire::test(ListChatMessages::class)->assertTableActionHidden('mute', $adminMessage);

    expect($customer->fresh()->isChatMuted())->toBeTrue();
    expect($employee->fresh()->isChatMuted())->toBeTrue();
    expect($otherAdmin->fresh()->isChatMuted())->toBeFalse();
});

test('an admin can delete any global message, but not a reservation group chat message', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin);

    $adminMessage = ChatMessage::create(['user_id' => User::factory()->create(['role' => 'admin'])->id, 'body' => 'staff']);
    $private = privateGroupMessage();

    Livewire::test(ListChatMessages::class)->callTableAction('delete', $adminMessage);
    expect(ChatMessage::find($adminMessage->id))->toBeNull();

    try {
        Livewire::test(ListChatMessages::class)->callTableAction('delete', $private);
    } catch (Throwable) {
        // Not being able to resolve the record at all is the point.
    }
    expect(ChatMessage::find($private->id))->not->toBeNull();
});
