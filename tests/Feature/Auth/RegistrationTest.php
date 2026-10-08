<?php

use App\Models\User;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'C0rrect!Horse42',
        'password_confirmation' => 'C0rrect!Horse42',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
    expect(User::where('email', 'test@example.com')->exists())->toBeTrue();
});

test('registration rejects an inappropriate display name', function () {
    $response = $this->post('/register', [
        'name' => 'fuck this',
        'email' => 'test@example.com',
        'password' => 'C0rrect!Horse42',
        'password_confirmation' => 'C0rrect!Horse42',
    ]);

    $response->assertSessionHasErrors('name');
    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

test('registration rejects a display name that is already taken', function () {
    User::factory()->create(['name' => 'Taken Name']);

    $response = $this->post('/register', [
        'name' => 'Taken Name',
        'email' => 'test@example.com',
        'password' => 'C0rrect!Horse42',
        'password_confirmation' => 'C0rrect!Horse42',
    ]);

    $response->assertSessionHasErrors('name');
    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

test('registration requires matching password confirmation', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'not-the-same',
    ]);

    $response->assertSessionHasErrors('password');
    $this->assertGuest();
});

test('non-string name or email is a validation error, not a server error', function () {
    $this->post('/register', [
        'name' => ['x'],
        'email' => ['a@b.c'],
        'password' => 'C0rrect!Horse42',
        'password_confirmation' => 'C0rrect!Horse42',
    ])->assertSessionHasErrors(['name', 'email']);

    $this->assertGuest();
});

test('registration rejects a case variant of an existing name', function () {
    User::factory()->create(['name' => 'Employee']);

    $this->post('/register', [
        'name' => 'EMPLOYEE',
        'email' => 'test@example.com',
        'password' => 'C0rrect!Horse42',
        'password_confirmation' => 'C0rrect!Horse42',
    ])->assertSessionHasErrors('name');

    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

test('registration rejects a name with invisible characters', function () {
    $this->post('/register', [
        'name' => "Ev\u{200B}e",
        'email' => 'test@example.com',
        'password' => 'C0rrect!Horse42',
        'password_confirmation' => 'C0rrect!Horse42',
    ])->assertSessionHasErrors('name');
});
