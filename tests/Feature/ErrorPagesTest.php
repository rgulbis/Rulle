<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => config(['app.debug' => false]));

it('renders the styled error page for an unknown url', function () {
    $this->get('/definitely-not-a-page')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('error')
            ->where('status', 404));
});

it('renders the styled error page for a forbidden page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/staff/scan')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('error')
            ->where('status', 403));
});

it('leaves failed in-page actions as plain responses for the toast', function () {
    $this->actingAs(User::factory()->create())
        ->withHeader('X-Inertia', 'true')
        ->post('/staff/scan')
        ->assertStatus(403);
});

it('leaves the admin panel to Filament', function () {
    $this->get('/admin/definitely-not-a-page')
        ->assertNotFound()
        ->assertDontSee('"component":"error"', false);
});

test('a JSON request for something that does not exist gets a plain not-found, not the model class', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/reservations/99999/chat');

    $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    expect($response->getContent())->not->toContain('App\\Models');
});

test('the not-found message follows the visitor\'s language', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withUnencryptedCookie('locale', 'lv')->withCredentials()
        ->getJson('/reservations/99999/chat')
        ->assertNotFound()->assertExactJson(['message' => 'Nav atrasts.']);
});
