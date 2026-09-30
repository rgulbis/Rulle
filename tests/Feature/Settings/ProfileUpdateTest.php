<?php

use App\Models\User;

test('the profile page is shown to logged-in users', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/profile'));
});

test('guests cannot open the profile page', function () {
    $this->get('/settings/profile')->assertRedirect('/login');
});

test('a user can change their name', function () {
    $user = User::factory()->create(['name' => 'Old Name']);

    $this->actingAs($user)
        ->from('/settings/profile')
        ->patch('/settings/profile', ['name' => '  New Name  '])
        ->assertRedirect('/settings/profile')
        ->assertSessionHas('status', 'profile-updated');

    expect($user->fresh()->name)->toBe('New Name');
});

test('the name is required and length-limited', function () {
    $user = User::factory()->create(['name' => 'Keep Me']);

    $this->actingAs($user)->patch('/settings/profile', ['name' => ''])
        ->assertSessionHasErrors('name');
    $this->actingAs($user)->patch('/settings/profile', ['name' => str_repeat('a', 256)])
        ->assertSessionHasErrors('name');

    expect($user->fresh()->name)->toBe('Keep Me');
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
