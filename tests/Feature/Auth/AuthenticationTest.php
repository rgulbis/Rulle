<?php

use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('admins are redirected to the filament panel after login', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    // The real login form submits via Inertia's XHR-based navigation, which
    // sends this header. Filament's admin panel is a separate, non-Inertia
    // app, so this redirect must use Inertia's "location visit" mechanism
    // (409 + Location header) rather than a plain redirect, or Inertia's
    // client tries to parse raw Filament HTML as an Inertia response and
    // shows its own error dialog instead of navigating.
    $response = $this->withHeaders(['X-Inertia' => 'true'])->post('/login', [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $response->assertStatus(409);
    $response->assertHeader('X-Inertia-Location', url('/admin'));
});

test('employees are redirected to the scanner after login', function () {
    $employee = User::factory()->create(['role' => 'employee']);

    $response = $this->post('/login', [
        'email' => $employee->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('staff.scan', absolute: false));
});

test('users cannot authenticate with an invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/login');
});
