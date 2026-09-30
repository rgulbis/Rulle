<?php

use App\Models\User;

test('server messages follow the frontend language cookie', function () {
    User::factory()->create(['email' => 'rider@example.com']);

    $this->withUnencryptedCookie('locale', 'lv')
        ->post('/login', ['email' => 'rider@example.com', 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => 'Šie pieteikšanās dati neatbilst mūsu ierakstiem.']);
});

test('server messages stay English without the cookie', function () {
    User::factory()->create(['email' => 'rider@example.com']);

    $this->post('/login', ['email' => 'rider@example.com', 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
});

test('validation messages use Latvian field names', function () {
    $this->withUnencryptedCookie('locale', 'lv')
        ->post('/forgot-password', ['email' => ''])
        ->assertSessionHasErrors(['email' => 'Lauks e-pasts ir obligāts.']);
});

test('an unsupported locale cookie is ignored', function () {
    $this->withUnencryptedCookie('locale', 'de')
        ->post('/forgot-password', ['email' => ''])
        ->assertSessionHasErrors(['email' => 'The email field is required.']);
});

test('scan results are translated for staff', function () {
    $staff = User::factory()->create(['role' => 'employee']);

    // withCredentials(): the test client only attaches cookies to JSON
    // requests when asked, like the scanner's fetch(credentials) does.
    $this->actingAs($staff)
        ->withCredentials()
        ->withUnencryptedCookie('locale', 'lv')
        ->postJson('/staff/scan', ['code' => 'not-a-real-code', 'mode' => 'entry'])
        ->assertNotFound()
        ->assertJson(['message' => 'Šim QR kodam neatbilst neviens lietotājs.']);
});

test('pages are told the chosen language so server rendering matches the browser', function () {
    $this->withUnencryptedCookie('locale', 'en')->get('/')
        ->assertInertia(fn ($page) => $page->where('locale', 'en'));
});

test('pages get no language when none was chosen yet', function () {
    $this->get('/')->assertInertia(fn ($page) => $page->where('locale', null));
});

test('an unsupported language cookie is not passed to the page', function () {
    $this->withUnencryptedCookie('locale', 'fr')->get('/')
        ->assertInertia(fn ($page) => $page->where('locale', null));
});
