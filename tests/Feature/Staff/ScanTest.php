<?php

use App\Events\OccupancyUpdated;
use App\Events\UserCheckInStatusUpdated;
use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\SubscriptionType;
use App\Models\User;
use Illuminate\Support\Facades\Event;

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

function makeActiveReservation(User $owner, array $attributes = []): Reservation
{
    return Reservation::create(array_merge([
        'user_id' => $owner->id,
        'starts_at' => now()->subMinutes(10),
        'ends_at' => now()->addHour(),
        'group_size' => 3,
        'price_cents' => 4500,
        'status' => 'active',
    ], $attributes));
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
    Event::fake([UserCheckInStatusUpdated::class]);

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
        'mode' => 'entry',
    ]);

    $response->assertOk()->assertJson([
        'found' => true,
        'allowed' => true,
        'name' => $client->name,
        'checked_in' => true,
    ]);
    expect($client->fresh()->checked_in)->toBeTrue();
    expect($client->activeOneTimePurchase()->visits_remaining)->toBe(1);
    Event::assertDispatched(
        UserCheckInStatusUpdated::class,
        fn (UserCheckInStatusUpdated $event) => $event->user->is($client) && $event->user->checked_in === true,
    );

    $response = $this->actingAs($staff)->postJson('/staff/scan', [
        'code' => $client->qr_code,
        'mode' => 'exit',
    ]);

    $response->assertOk()->assertJson(['checked_in' => false]);
    expect($client->fresh()->checked_in)->toBeFalse();
    expect($client->activeOneTimePurchase()->visits_remaining)->toBe(1);
});

test('a successful scan broadcasts the updated occupancy count for the livestream page', function () {
    Event::fake([OccupancyUpdated::class]);

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

    $this->actingAs($staff)->postJson('/staff/scan', ['code' => $client->qr_code, 'mode' => 'entry']);

    Event::assertDispatched(OccupancyUpdated::class);
    // The public headcount, not who's inside — broadcastWith() must never
    // grow to include names or ids.
    expect((new OccupancyUpdated)->broadcastWith())->toBe(['count' => 1]);
});

test('scanning for entry while already checked in is rejected', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $client = User::factory()->create(['role' => 'user', 'checked_in' => true]);

    $response = $this->actingAs($staff)->postJson('/staff/scan', [
        'code' => $client->qr_code,
        'mode' => 'entry',
    ]);

    $response->assertStatus(409)->assertJson([
        'found' => true,
        'allowed' => false,
        'name' => $client->name,
        'message' => 'Already checked in.',
    ]);
    expect($client->fresh()->checked_in)->toBeTrue();
});

test('scanning for exit while not checked in is rejected', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $client = User::factory()->create(['role' => 'user', 'checked_in' => false]);

    $response = $this->actingAs($staff)->postJson('/staff/scan', [
        'code' => $client->qr_code,
        'mode' => 'exit',
    ]);

    $response->assertStatus(409)->assertJson([
        'found' => true,
        'allowed' => false,
        'name' => $client->name,
        'message' => 'Not currently checked in.',
    ]);
    expect($client->fresh()->checked_in)->toBeFalse();
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
            'mode' => $i % 2 === 0 ? 'entry' : 'exit',
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
        'mode' => 'entry',
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
        'mode' => 'entry',
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
        'mode' => 'exit',
    ]);

    $response->assertOk()->assertJson(['allowed' => true, 'checked_in' => false]);
});

test('scanning an unknown code returns not found', function () {
    $staff = User::factory()->create(['role' => 'employee']);

    $response = $this->actingAs($staff)->postJson('/staff/scan', [
        'code' => 'does-not-exist',
        'mode' => 'entry',
    ]);

    $response->assertNotFound()->assertJson(['found' => false]);
});

test('each user gets a unique qr code on creation', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    expect($a->qr_code)->not->toBeNull();
    expect($a->qr_code)->not->toBe($b->qr_code);
});

test('a customer outside an active reservation is denied entry', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $owner = User::factory()->create();
    makeActiveReservation($owner);

    $outsider = User::factory()->create(['checked_in' => false]);
    $type = makeSubscriptionType(['unlimited_entries' => true, 'visit_limit' => null]);
    Purchase::create([
        'user_id' => $outsider->id,
        'subscription_type_id' => $type->id,
        'stripe_checkout_session_id' => 'cs_test_reservation_outsider',
        'status' => 'active',
        'valid_date' => now()->toDateString(),
    ]);

    $response = $this->actingAs($staff)->postJson('/staff/scan', [
        'code' => $outsider->qr_code,
        'mode' => 'entry',
    ]);

    $response->assertForbidden()->assertJson(['found' => true, 'allowed' => false]);
    expect($outsider->fresh()->checked_in)->toBeFalse();
});

test('the reservation owner can still enter during their own reservation', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $owner = User::factory()->create(['checked_in' => false]);
    makeActiveReservation($owner);

    $response = $this->actingAs($staff)->postJson('/staff/scan', [
        'code' => $owner->qr_code,
        'mode' => 'entry',
    ]);

    $response->assertOk()->assertJson(['allowed' => true, 'checked_in' => true]);
});

test('a reservation participant can still enter during the reservation', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $owner = User::factory()->create();
    $reservation = makeActiveReservation($owner);
    $participant = User::factory()->create(['checked_in' => false]);
    $reservation->participants()->attach($participant->id);

    $response = $this->actingAs($staff)->postJson('/staff/scan', [
        'code' => $participant->qr_code,
        'mode' => 'entry',
    ]);

    $response->assertOk()->assertJson(['allowed' => true, 'checked_in' => true]);
});

test('staff can still enter during an active reservation', function () {
    $staff = User::factory()->create(['role' => 'employee', 'checked_in' => false]);
    $owner = User::factory()->create();
    makeActiveReservation($owner);

    // Staff still go through the normal (pre-existing, unrelated to
    // reservations) subscription check on entry, so they need access same
    // as any other entry — what this test actually verifies is that they
    // don't get the "privately reserved" rejection a customer would.
    $type = makeSubscriptionType(['unlimited_entries' => true, 'visit_limit' => null]);
    Purchase::create([
        'user_id' => $staff->id,
        'subscription_type_id' => $type->id,
        'stripe_checkout_session_id' => 'cs_test_staff_access',
        'status' => 'active',
        'valid_date' => now()->toDateString(),
    ]);

    $response = $this->actingAs($staff)->postJson('/staff/scan', [
        'code' => $staff->qr_code,
        'mode' => 'entry',
    ]);

    $response->assertOk()->assertJson(['allowed' => true, 'checked_in' => true]);
});

test('reservation exclusivity does not block checking out', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $owner = User::factory()->create();
    makeActiveReservation($owner);

    $outsider = User::factory()->create(['checked_in' => true]);

    $response = $this->actingAs($staff)->postJson('/staff/scan', [
        'code' => $outsider->qr_code,
        'mode' => 'exit',
    ]);

    $response->assertOk()->assertJson(['allowed' => true, 'checked_in' => false]);
});
