<?php

use App\Models\ChatMessage;
use App\Models\Reservation;
use App\Models\User;

test('the account page is shown to logged-in users and not to guests', function () {
    $this->get('/settings/account')->assertRedirect('/login');

    $this->actingAs(User::factory()->create())
        ->get('/settings/account')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/account')
            ->where('canDelete', true)
            ->where('deleteBlocked', false));
});

test('the data export holds the user\'s own data and no secrets', function () {
    $user = User::factory()->create(['name' => 'Jane Rider']);
    $other = User::factory()->create(['name' => 'Someone Else']);
    ChatMessage::create(['user_id' => $user->id, 'body' => 'hello from Jane']);
    ChatMessage::create(['user_id' => $other->id, 'body' => 'not Jane']);

    $response = $this->actingAs($user)->get('/settings/account/export');

    $response->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="my-data.json"');

    $json = $response->json();

    expect($json['account']['name'])->toBe('Jane Rider')
        ->and($json['chat_messages'])->toHaveCount(1)
        ->and($json['chat_messages'][0]['body'])->toBe('hello from Jane');

    $raw = $response->getContent();

    expect($raw)->not->toContain($user->qr_code)
        ->and($raw)->not->toContain('not Jane')
        ->and($raw)->not->toContain('password');
});

test('a customer can delete their account with the right password', function () {
    fakeStripe();
    $user = User::factory()->create(['email' => 'jane@example.com']);
    $user->forceFill(['password' => 'Correct-Horse-9!'])->save();

    $this->actingAs($user)
        ->delete('/settings/account', ['password' => 'Correct-Horse-9!'])
        ->assertRedirect('/login');

    $this->assertGuest();
    expect(User::withTrashed()->find($user->id)->trashed())->toBeTrue()
        ->and(User::withTrashed()->find($user->id)->email)->toBe("deleted-{$user->id}@deleted.invalid");
});

test('deleting the account needs the current password', function () {
    fakeStripe();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/settings/account')
        ->delete('/settings/account', ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password');

    expect($user->fresh()->trashed())->toBeFalse();
});

test('an upcoming paid reservation blocks deletion', function () {
    fakeStripe();
    $user = User::factory()->create();
    $user->forceFill(['password' => 'Correct-Horse-9!'])->save();
    Reservation::create([
        'user_id' => $user->id,
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'group_size' => 2,
        'price_cents' => 2000,
        'status' => 'active',
        'payment_status' => 'paid',
    ]);

    $this->actingAs($user)
        ->get('/settings/account')
        ->assertInertia(fn ($page) => $page->where('deleteBlocked', true));

    $this->actingAs($user)
        ->from('/settings/account')
        ->delete('/settings/account', ['password' => 'Correct-Horse-9!'])
        ->assertSessionHasErrors('password');

    expect($user->fresh()->trashed())->toBeFalse();
});

test('staff accounts cannot be closed from the settings page', function () {
    $employee = User::factory()->create();
    $employee->forceFill(['role' => 'employee'])->save();

    $this->actingAs($employee)
        ->delete('/settings/account', ['password' => 'whatever'])
        ->assertForbidden();
});
