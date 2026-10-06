<?php

use App\Models\CheckInEvent;
use App\Models\Purchase;
use App\Models\User;
use App\Support\CheckInOccupancy;
use App\Support\EntryToken;

/*
| The entry QR is a short-lived, signed, single-entry token; scans are one
| atomic decision; and forgotten check-outs are reconciled at closing.
*/

function staffAndPassHolder(int $visits = 3): array
{
    $staff = User::factory()->create(['role' => 'employee']);
    $client = User::factory()->create();
    Purchase::create([
        'user_id' => $client->id,
        'subscription_type_id' => makeSubscriptionType(['visit_limit' => $visits])->id,
        'stripe_checkout_session_id' => 'cs_scan_'.$client->id,
        'status' => 'active',
        'visits_remaining' => $visits,
        'payment_status' => 'paid',
    ]);

    return [$staff, $client];
}

test('the permanent user id is not a valid entry code any more', function () {
    [$staff, $client] = staffAndPassHolder();

    $this->actingAs($staff)->postJson('/staff/scan', ['code' => $client->qr_code, 'mode' => 'entry'])->assertNotFound();
});

test('a forged or tampered token is refused', function () {
    [$staff, $client] = staffAndPassHolder();
    [$qr, $expires, $nonce, $signature] = explode('.', entryCode($client));

    $this->actingAs($staff)->postJson('/staff/scan', ['code' => "{$qr}.{$expires}.{$nonce}.forgedsignature", 'mode' => 'entry'])->assertNotFound();
    // A longer lifetime than was signed.
    $this->actingAs($staff)->postJson('/staff/scan', ['code' => "{$qr}.".($expires + 3600).".{$nonce}.{$signature}", 'mode' => 'entry'])->assertNotFound();
});

test('an old screenshot stops working once the token expires', function () {
    [$staff, $client] = staffAndPassHolder();
    $screenshot = entryCode($client);

    $this->travel(2)->minutes();

    $this->actingAs($staff)->postJson('/staff/scan', ['code' => $screenshot, 'mode' => 'entry'])->assertNotFound();
    expect($client->isCurrentlyCheckedIn())->toBeFalse();
});

test('the same token cannot be redeemed twice, even after the owner left', function () {
    [$staff, $client] = staffAndPassHolder();
    $token = entryCode($client);

    $this->actingAs($staff)->postJson('/staff/scan', ['code' => $token, 'mode' => 'entry'])->assertOk();
    $this->actingAs($staff)->postJson('/staff/scan', ['code' => entryCode($client), 'mode' => 'exit'])->assertOk();

    // The shared screenshot, scanned for a second entry while the real owner is outside.
    $this->actingAs($staff)->postJson('/staff/scan', ['code' => $token, 'mode' => 'entry'])->assertStatus(409);

    expect($client->isCurrentlyCheckedIn())->toBeFalse();
    expect(Purchase::first()->visits_remaining)->toBe(2);
});

test('a denied scan does not burn the customer\'s code', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $client = User::factory()->create(); // no pass
    $token = entryCode($client);

    $this->actingAs($staff)->postJson('/staff/scan', ['code' => $token, 'mode' => 'entry'])->assertForbidden();

    Purchase::create([
        'user_id' => $client->id,
        'subscription_type_id' => makeSubscriptionType()->id,
        'stripe_checkout_session_id' => 'cs_late_pass',
        'status' => 'active',
        'visits_remaining' => 1,
        'payment_status' => 'paid',
    ]);

    $this->actingAs($staff)->postJson('/staff/scan', ['code' => $token, 'mode' => 'entry'])->assertOk();
});

test('two scans of the same person spend one visit and write one event', function () {
    [$staff, $client] = staffAndPassHolder(visits: 1);

    // Two requests racing on one person: the second one arrives after the
    // first has been decided and must see its result, not stale state.
    $first = $this->actingAs($staff)->postJson('/staff/scan', ['code' => entryCode($client), 'mode' => 'entry']);
    $second = $this->actingAs($staff)->postJson('/staff/scan', ['code' => entryCode($client), 'mode' => 'entry']);

    expect([$first->status(), $second->status()])->toBe([200, 409]);
    expect(CheckInEvent::where('user_id', $client->id)->count())->toBe(1);
    expect(Purchase::first())->visits_remaining->toBe(0)->status->toBe('used_up');
});

test('the dashboard hands a verified customer a fresh token, and the permanent id never reaches the browser', function () {
    $client = User::factory()->create();

    $response = $this->actingAs($client)->getJson('/dashboard/entry-token')->assertOk();
    expect(EntryToken::resolve($response->json('token'))?->is($client))->toBeTrue();

    $this->actingAs($client)->get('/dashboard')->assertInertia(fn ($page) => $page->missing('auth.user.qr_code'));
    expect(str_contains($this->actingAs($client)->get('/dashboard')->getContent(), $client->qr_code))->toBeFalse();

    $this->actingAs(User::factory()->unverified()->create())->getJson('/dashboard/entry-token')->assertForbidden();
});

test('someone left checked in overnight is checked out at closing, so the headcount and next-day entry are right', function () {
    makeReservationSettings(['closing_time' => '23:00']);
    $this->travelTo(now()->startOfDay()->addHours(20));
    $late = User::factory()->create();
    CheckInEvent::create(['user_id' => $late->id, 'checked_in' => true]);
    expect(CheckInOccupancy::currentlyCheckedInCount())->toBe(1);

    // Still open at 22:00: nobody is closed out.
    $this->travelTo(now()->startOfDay()->addHours(22));
    expect(CheckInOccupancy::closeStaleCheckIns())->toBe(0);

    // Next morning.
    $this->travelTo(now()->addDay()->startOfDay()->addHours(9));
    $this->artisan('checkins:close-stale')->assertSuccessful();

    expect(CheckInOccupancy::currentlyCheckedInCount())->toBe(0);
    expect($late->isCurrentlyCheckedIn())->toBeFalse();
    // Backdated to the closing time, so occupancy history doesn't show them overnight.
    expect(CheckInEvent::where('user_id', $late->id)->orderByDesc('id')->first()->created_at->format('H:i'))->toBe('23:00');
});

test('someone inside during opening hours is not checked out before the park closes', function () {
    makeReservationSettings(['closing_time' => '23:00']);
    $this->travelTo(now()->startOfDay()->addHours(10));
    $rider = User::factory()->create();
    CheckInEvent::create(['user_id' => $rider->id, 'checked_in' => true]);

    $this->travelTo(now()->startOfDay()->addHours(15));

    expect(CheckInOccupancy::closeStaleCheckIns())->toBe(0);
    expect($rider->isCurrentlyCheckedIn())->toBeTrue();
});
