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

test('registering with a unicode email domain can log in again after logging out', function () {
    $this->post('/register', [
        'name' => 'Unicode Rider',
        'email' => 'rider@rullē.lv',
        'password' => 'Str0ng!Passw0rd#xyz',
        'password_confirmation' => 'Str0ng!Passw0rd#xyz',
    ])->assertRedirect();

    // Stored the way login and password reset look it up.
    expect(User::where('email', 'rider@xn--rull-eva.lv')->exists())->toBeTrue();
    expect(User::where('email', 'rider@rullē.lv')->exists())->toBeFalse();

    $this->post('/logout');
    $this->assertGuest();

    $this->post('/login', ['email' => 'rider@rullē.lv', 'password' => 'Str0ng!Passw0rd#xyz'])->assertRedirect();
    $this->assertAuthenticated();
});

test('the unicode and punycode spellings of one address cannot both register', function () {
    User::factory()->create(['email' => 'rider@xn--rull-eva.lv']);

    $this->post('/register', [
        'name' => 'Second Rider',
        'email' => 'rider@rullē.lv',
        'password' => 'Str0ng!Passw0rd#xyz',
        'password_confirmation' => 'Str0ng!Passw0rd#xyz',
    ])->assertSessionHasErrors('email');
});
