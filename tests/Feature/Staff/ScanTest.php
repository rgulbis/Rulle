<?php

use App\Models\Purchase;
use App\Models\SubscriptionType;
use App\Models\User;

function makeSubscriptionType(array $attributes = []): SubscriptionType
{
    // Avoid the model's `saved` hook making a real Stripe API call in tests.
    return SubscriptionType::withoutEvents(fn () => SubscriptionType::create(array_merge([
        'name' => 'Day pass',
        'price_cents' => 500,
        'billing_interval' => 'one_time',
        'visit_limit' => 1,
    ], $attributes)));
}

test('non-staff users cannot access the scanner', function () {
    $client = User::factory()->create(['role' => 'user']);

    $response = $this->actingAs($client)->get('/staff/scan');

    $response->assertForbidden();
});

test('admins can also access the scanner', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get('/staff/scan');

    $response->assertOk();
});

test('staff users can access the scanner', function () {
    $staff = User::factory()->create(['role' => 'employee']);

    $response = $this->actingAs($staff)->get('/staff/scan');

    $response->assertOk();
});

test('scanning a client with an active purchase toggles check-in and consumes a visit', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $client = User::factory()->create(['role' => 'user', 'checked_in' => false]);
    $type = makeSubscriptionType(['visit_limit' => 2]);
    Purchase::create([
        'user_id' => $client->id,
        'subscription_type_id' => $type->id,
        'stripe_checkout_session_id' => 'cs_test_1',
        'status' => 'active',
        'visits_remaining' => 2,
    ]);

    $response = $this->actingAs($staff)->postJson('/staff/scan', [
        'code' => $client->qr_code,
    ]);

    $response->assertOk()->assertJson([
        'found' => true,
        'allowed' => true,
        'name' => $client->name,
        'checked_in' => true,
    ]);
    expect($client->fresh()->checked_in)->toBeTrue();
    expect($client->activeOneTimePurchase()->visits_remaining)->toBe(1);

    $response = $this->actingAs($staff)->postJson('/staff/scan', [
        'code' => $client->qr_code,
    ]);

    $response->assertOk()->assertJson(['checked_in' => false]);
    expect($client->fresh()->checked_in)->toBeFalse();
    expect($client->activeOneTimePurchase()->visits_remaining)->toBe(1);
});

test('scanning a client with an unlimited-entries day pass allows repeated entries without consuming visits', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $client = User::factory()->create(['role' => 'user', 'checked_in' => false]);
    $type = makeSubscriptionType(['unlimited_entries' => true, 'visit_limit' => null]);
    Purchase::create([
        'user_id' => $client->id,
        'subscription_type_id' => $type->id,
        'stripe_checkout_session_id' => 'cs_test_unlimited',
        'status' => 'active',
        'valid_date' => now()->toDateString(),
    ]);

    // Enter, then exit, then enter again — none of this should be blocked
    // or decrement anything, since the pass is unlimited for the day.
    for ($i = 0; $i < 4; $i++) {
        $response = $this->actingAs($staff)->postJson('/staff/scan', [
            'code' => $client->qr_code,
        ]);

        $response->assertOk()->assertJson(['allowed' => true]);
    }

    expect($client->activeOneTimePurchase())->not->toBeNull();
});

test('an unlimited-entries day pass is not usable on a different day', function () {
    $client = User::factory()->create(['role' => 'user']);
    $type = makeSubscriptionType(['unlimited_entries' => true, 'visit_limit' => null]);
    Purchase::create([
        'user_id' => $client->id,
        'subscription_type_id' => $type->id,
        'stripe_checkout_session_id' => 'cs_test_yesterday',
        'status' => 'active',
        'valid_date' => now()->subDay()->toDateString(),
    ]);

    expect($client->activeOneTimePurchase())->toBeNull();
});

test('scanning a client with no active subscription denies entry', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $client = User::factory()->create(['role' => 'user', 'checked_in' => false]);

    $response = $this->actingAs($staff)->postJson('/staff/scan', [
        'code' => $client->qr_code,
    ]);

    $response->assertForbidden()->assertJson([
        'found' => true,
        'allowed' => false,
        'name' => $client->name,
    ]);
    expect($client->fresh()->checked_in)->toBeFalse();
});

test('scanning a client with unverified email denies entry even with active access', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $client = User::factory()->unverified()->create(['role' => 'user', 'checked_in' => false]);
    $type = makeSubscriptionType(['unlimited_entries' => true, 'visit_limit' => null]);
    Purchase::create([
        'user_id' => $client->id,
        'subscription_type_id' => $type->id,
        'stripe_checkout_session_id' => 'cs_test_unverified',
        'status' => 'active',
        'valid_date' => now()->toDateString(),
    ]);

    $response = $this->actingAs($staff)->postJson('/staff/scan', [
        'code' => $client->qr_code,
    ]);

    $response->assertForbidden()->assertJson([
        'found' => true,
        'allowed' => false,
        'message' => 'Email not verified.',
    ]);
    expect($client->fresh()->checked_in)->toBeFalse();
});

test('checking out does not require an active subscription', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $client = User::factory()->create(['role' => 'user', 'checked_in' => true]);

    $response = $this->actingAs($staff)->postJson('/staff/scan', [
        'code' => $client->qr_code,
    ]);

    $response->assertOk()->assertJson(['allowed' => true, 'checked_in' => false]);
});

test('scanning an unknown code returns not found', function () {
    $staff = User::factory()->create(['role' => 'employee']);

    $response = $this->actingAs($staff)->postJson('/staff/scan', [
        'code' => 'does-not-exist',
    ]);

    $response->assertNotFound()->assertJson(['found' => false]);
});

test('each user gets a unique qr code on creation', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    expect($a->qr_code)->not->toBeNull();
    expect($a->qr_code)->not->toBe($b->qr_code);
});
