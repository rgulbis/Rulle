<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

test('email verification screen can be rendered', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get('/verify-email');

    $response->assertStatus(200);
});

test('unverified users can still access the dashboard', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
});

test('employees are redirected away from the dashboard', function () {
    $employee = User::factory()->create(['role' => 'employee']);

    $response = $this->actingAs($employee)->get('/dashboard');

    $response->assertRedirect(route('staff.scan', absolute: false));
});

test('admins are redirected away from the dashboard', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get('/dashboard');

    $response->assertRedirect('/admin');
});

test('email can be verified', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
});

test('email is not verified with an invalid hash', function () {
    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')],
    );

    $this->actingAs($user)->get($verificationUrl);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('a new verification email can be requested', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post('/email/verification-notification');

    Notification::assertSentTo($user, VerifyEmail::class);
});
