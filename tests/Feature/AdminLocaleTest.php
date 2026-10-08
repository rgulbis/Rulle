<?php

use App\Models\User;

function localeAdmin(): User
{
    $admin = User::factory()->create();
    $admin->forceFill(['role' => 'admin'])->save();

    return $admin;
}

test('the locale route sets the language cookie and goes back', function () {
    $this->from('/admin')
        ->get('/locale/lv')
        ->assertRedirect('/admin')
        ->assertCookie('locale', 'lv', false);
});

test('an unknown language is refused', function () {
    $this->get('/locale/xx')->assertNotFound();
});

test('the admin panel is in English by default', function () {
    $this->actingAs(localeAdmin())
        ->get('/admin/purchases')
        ->assertOk()
        ->assertSee('One-Time Passes');
});

test('the admin panel follows the language cookie', function () {
    $this->actingAs(localeAdmin())
        ->withUnencryptedCookie('locale', 'lv')
        ->get('/admin/purchases')
        ->assertOk()
        ->assertSee('Vienreizējās caurlaides')
        ->assertSee('Atpakaļ uz vietni')
        ->assertDontSee('One-Time Passes');
});
