<?php

use App\Models\Purchase;
use App\Models\User;

test('a QR code lets a pass holder in and turns a visitor away', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $rider = User::factory()->create();
    $visitor = User::factory()->create();
    Purchase::create([
        'user_id' => $rider->id,
        'subscription_type_id' => makeSubscriptionType(['visit_limit' => 2])->id,
        'stripe_checkout_session_id' => 'cs_rider',
        'status' => 'active',
        'visits_remaining' => 2,
    ]);

    // The rider's dashboard shows the entry code and says they are outside.
    $this->actingAs($rider);
    visit('/dashboard')
        ->assertSee('Ieejas karte')
        ->assertSee('Nav parkā')
        ->assertPresent('canvas, svg');

    // At the gate the staff member opens the scanner in entry mode.
    $this->actingAs($staff);
    $scanner = visit('/staff/scan')
        ->assertSee('Skenēt biedra QR kodu')
        ->assertSee('Ieeja');

    $scan = fn (string $code) => $scanner->script(
        'fetch("/staff/scan", {method: "POST", credentials: "same-origin", headers: {"Content-Type": "application/json", "Accept": "application/json", "X-XSRF-TOKEN": decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)[1])}, body: JSON.stringify({code: "'.$code.'", mode: "entry"})}).then(r => r.json())',
    );

    expect($scan(qrTokenFor($visitor)))->toMatchArray(['allowed' => false, 'message' => 'Nav aktīva abonementa.']);
    expect($scan(qrTokenFor($rider)))->toMatchArray(['allowed' => true, 'checked_in' => true]);

    // The visit is logged and the rider's dashboard now shows them inside.
    $this->actingAs($rider);
    visit('/dashboard')->assertSee('Parkā');

    expect(Purchase::firstWhere('user_id', $rider->id)->visits_remaining)->toBe(1);
});
