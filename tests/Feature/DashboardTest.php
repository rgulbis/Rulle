<?php

use App\Models\CheckInEvent;
use App\Models\User;

test('the shared auth user prop reflects current check-in status', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('auth.user.checked_in', false)
    );

    CheckInEvent::create(['user_id' => $user->id, 'checked_in' => true]);

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('auth.user.checked_in', true)
    );
});
