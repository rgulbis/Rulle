<?php

use App\Models\User;

test('a guest can view the livestream page without logging in', function () {
    $response = $this->get('/livestream');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('livestream/index'));
});

test('any logged-in role can also view the livestream page', function () {
    foreach (['user', 'employee', 'admin'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]))
            ->get('/livestream')
            ->assertOk();
    }
});

test('the site root is the same public livestream page, not a login wall', function () {
    $this->get('/')->assertInertia(fn ($page) => $page->component('livestream/index'));

    // Previously '/' was guest-only and bounced a logged-in visitor to their
    // dashboard — it should just show the same public page now instead.
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertInertia(fn ($page) => $page->component('livestream/index'));
});
