<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('password update screen can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/settings/password');

    $response->assertStatus(200);
});

test('password can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->put('/settings/password', [
            'current_password' => 'password',
            'password' => 'N3w!Str0ngPass',
            'password_confirmation' => 'N3w!Str0ngPass',
        ]);

    $response->assertSessionHasNoErrors();
    expect(Hash::check('N3w!Str0ngPass', $user->fresh()->password))->toBeTrue();
});

test('correct current password must be provided to update password', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->put('/settings/password', [
            'current_password' => 'wrong-password',
            'password' => 'N3w!Str0ngPass',
            'password_confirmation' => 'N3w!Str0ngPass',
        ]);

    $response->assertSessionHasErrors('current_password');
});
