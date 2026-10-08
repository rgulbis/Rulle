<?php

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Livewire\Livewire;

test('the profile page is shown to logged-in users', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/profile'));
});

test('guests cannot open the profile page', function () {
    $this->get('/settings/profile')->assertRedirect('/login');
});

test('a name change goes to pending review, not straight to the visible name', function () {
    $user = User::factory()->create(['name' => 'Old Name']);

    $this->actingAs($user)
        ->from('/settings/profile')
        ->patch('/settings/profile', ['name' => '  New Name  '])
        ->assertRedirect('/settings/profile')
        ->assertSessionHas('status', 'profile-pending');

    // The name everyone else sees (chat, reservations, ...) doesn't change
    // until an admin approves the request.
    expect($user->fresh()->name)->toBe('Old Name');
    expect($user->fresh()->pending_name)->toBe('New Name');
});

test('resubmitting your current name clears any pending request instead of creating one', function () {
    $user = User::factory()->create(['name' => 'Old Name', 'pending_name' => 'Something Else']);

    $this->actingAs($user)
        ->patch('/settings/profile', ['name' => 'Old Name'])
        ->assertSessionHas('status', 'profile-updated');

    expect($user->fresh()->pending_name)->toBeNull();
});

test('the profile page shows the pending name while it awaits review', function () {
    $user = User::factory()->create(['name' => 'Old Name', 'pending_name' => 'New Name']);

    $this->actingAs($user)->get('/settings/profile')
        ->assertInertia(fn ($page) => $page->where('pendingName', 'New Name'));
});

test('the name is required and length-limited', function () {
    $user = User::factory()->create(['name' => 'Keep Me']);

    $this->actingAs($user)->patch('/settings/profile', ['name' => ''])
        ->assertSessionHasErrors('name');
    $this->actingAs($user)->patch('/settings/profile', ['name' => str_repeat('a', 256)])
        ->assertSessionHasErrors('name');

    expect($user->fresh()->name)->toBe('Keep Me');
    expect($user->fresh()->pending_name)->toBeNull();
});

test('an inappropriate name is rejected before it even reaches pending review', function () {
    $user = User::factory()->create(['name' => 'Keep Me']);

    $this->actingAs($user)->patch('/settings/profile', ['name' => 'kurva'])
        ->assertSessionHasErrors('name');

    expect($user->fresh()->name)->toBe('Keep Me');
    expect($user->fresh()->pending_name)->toBeNull();
});

test('a name change is rejected if another user already has that name', function () {
    User::factory()->create(['name' => 'Taken Name']);
    $user = User::factory()->create(['name' => 'Keep Me']);

    $this->actingAs($user)->patch('/settings/profile', ['name' => 'Taken Name'])
        ->assertSessionHasErrors('name');

    expect($user->fresh()->pending_name)->toBeNull();
});

test('a name change is rejected if another user already requested that exact name', function () {
    User::factory()->create(['name' => 'Someone Else', 'pending_name' => 'Trendy Name']);
    $user = User::factory()->create(['name' => 'Keep Me']);

    $this->actingAs($user)->patch('/settings/profile', ['name' => 'Trendy Name'])
        ->assertSessionHasErrors('name');

    expect($user->fresh()->pending_name)->toBeNull();
});

test('changing the name cannot change anything else', function () {
    $user = User::factory()->create(['role' => 'user']);

    $this->actingAs($user)->patch('/settings/profile', [
        'name' => 'Sneaky',
        'role' => 'admin',
        'email' => 'other@example.com',
    ]);

    $user->refresh();
    expect($user->role)->toBe('user')
        ->and($user->email)->not->toBe('other@example.com');
});

test('an admin approving a name change applies it', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['name' => 'Old Name', 'pending_name' => 'New Name']);

    $this->actingAs($admin);
    Livewire::test(ListUsers::class)->callTableAction('approveName', $user);

    expect($user->fresh()->name)->toBe('New Name');
    expect($user->fresh()->pending_name)->toBeNull();
});

test('an admin rejecting a name change discards it without touching the current name', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['name' => 'Old Name', 'pending_name' => 'New Name']);

    $this->actingAs($admin);
    Livewire::test(ListUsers::class)->callTableAction('rejectName', $user);

    expect($user->fresh()->name)->toBe('Old Name');
    expect($user->fresh()->pending_name)->toBeNull();
});

test('the approve/reject name actions are hidden for users with no pending request', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['name' => 'Old Name']);

    $this->actingAs($admin);
    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('approveName', $user)
        ->assertTableActionHidden('rejectName', $user);
});

test('a non-string name is a validation error, not a server error', function () {
    $user = User::factory()->create(['name' => 'Keep Me']);

    $this->actingAs($user)->patch('/settings/profile', ['name' => ['x']])
        ->assertSessionHasErrors('name');
    $this->actingAs($user)->patch('/settings/profile', ['name' => ['a' => 'b']])
        ->assertSessionHasErrors('name');

    expect($user->fresh()->pending_name)->toBeNull();
});

test('a name with invisible or markup characters is refused', function (string $name) {
    $user = User::factory()->create(['name' => 'Keep Me']);

    $this->actingAs($user)->patch('/settings/profile', ['name' => $name])
        ->assertSessionHasErrors('name');

    expect($user->fresh()->pending_name)->toBeNull();
})->with([
    'zero-width space' => "Ev\u{200B}e",
    'right-to-left override' => "\u{202E}evil",
    'control character' => "a\u{0007}b",
    'html' => '<b>bold</b>',
]);

test('a case variant or lookalike of another rider is refused', function () {
    User::factory()->create(['name' => 'Employee']);
    $user = User::factory()->create(['name' => 'Keep Me']);

    $this->actingAs($user)->patch('/settings/profile', ['name' => 'EMPLOYEE'])
        ->assertSessionHasErrors('name');
    $this->actingAs($user)->patch('/settings/profile', ['name' => "\u{0395}mployee"])
        ->assertSessionHasErrors('name');

    expect($user->fresh()->pending_name)->toBeNull();
});

test('changing only the case of your own name is allowed and goes to review', function () {
    $user = User::factory()->create(['name' => 'roberts']);

    $this->actingAs($user)->patch('/settings/profile', ['name' => 'Roberts'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'profile-pending');

    expect($user->fresh()->pending_name)->toBe('Roberts');
});
