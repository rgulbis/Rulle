<?php

use App\Models\User;

test('the admin panel has a way back to the rider-facing site', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->get('/admin')
        ->assertOk()
        ->assertSee('Back to site')
        ->assertSee('<a href="/" class="fi-dropdown-list-item', false);
});
