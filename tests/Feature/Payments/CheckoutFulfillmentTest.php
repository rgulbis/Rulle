<?php

use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/*
| Stripe's webhook is the source of truth for "this checkout was paid": the
| customer's browser may never come back to the success URL. The success URL
| runs the same idempotent code, so either can arrive first, or both.
*/

function webhookFor(string $type, array $session): TestResponse
{
    config(['cashier.webhook.secret' => null]);

    return test()->postJson('/stripe/webhook', [
        'type' => $type,
        'data' => ['object' => array_merge([
            'id' => 'cs_x',
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_cs_x',
        ], $session)],
    ]);
}

function pendingPurchase(User $user, string $sessionId, array $attributes = []): Purchase
{
    return Purchase::create(array_merge([
        'user_id' => $user->id,
        'subscription_type_id' => makeSubscriptionType(['visit_limit' => 5])->id,
        'stripe_checkout_session_id' => $sessionId,
        'price_cents' => 1000,
        'status' => 'pending',
    ], $attributes));
}

function pendingReservation(User $user, string $sessionId, ?DateTimeInterface $start = null, array $attributes = []): Reservation
{
    $start = $start ? now()->setTimestamp($start->getTimestamp()) : now()->addDays(3)->setTime(14, 0);

    return makeReservation($user, $start, $start->copy()->addHour(), array_merge([
        'status' => 'pending',
        'stripe_checkout_session_id' => $sessionId,
    ], $attributes));
}

test('a paid checkout fulfils the one-time pass from the webhook alone', function () {
    $purchase = pendingPurchase(User::factory()->create(), 'cs_pass');

    webhookFor('checkout.session.completed', ['id' => 'cs_pass'])->assertOk();

    $purchase->refresh();
    expect($purchase->status)->toBe('active');
    expect($purchase->payment_status)->toBe('paid');
    expect($purchase->visits_remaining)->toBe(5);
    expect($purchase->valid_date->isToday())->toBeTrue();
});

test('a paid checkout activates the reservation from the webhook alone', function () {
    $reservation = pendingReservation(User::factory()->create(), 'cs_res');

    webhookFor('checkout.session.completed', ['id' => 'cs_res'])->assertOk();

    expect($reservation->fresh()->status)->toBe('active');
    expect($reservation->fresh()->payment_status)->toBe('paid');
});

test('delivering the same event twice changes nothing the second time', function () {
    $purchase = pendingPurchase(User::factory()->create(), 'cs_twice');

    webhookFor('checkout.session.completed', ['id' => 'cs_twice'])->assertOk();
    // The pass is spent a little, then Stripe redelivers the event.
    $purchase->update(['visits_remaining' => 3]);
    webhookFor('checkout.session.completed', ['id' => 'cs_twice'])->assertOk();
    webhookFor('checkout.session.async_payment_succeeded', ['id' => 'cs_twice'])->assertOk();

    expect($purchase->fresh()->visits_remaining)->toBe(3);
    expect($purchase->fresh()->status)->toBe('active');
});

test('an unpaid completed session grants nothing until the async payment succeeds', function () {
    $reservation = pendingReservation(User::factory()->create(), 'cs_async');

    webhookFor('checkout.session.completed', ['id' => 'cs_async', 'payment_status' => 'unpaid'])->assertOk();
    expect($reservation->fresh()->status)->toBe('pending');
    expect($reservation->fresh()->payment_status)->toBe('unpaid');

    webhookFor('checkout.session.async_payment_succeeded', ['id' => 'cs_async'])->assertOk();
    expect($reservation->fresh()->status)->toBe('active');
});

test('a session that is not ours is acknowledged and ignored', function () {
    webhookFor('checkout.session.completed', ['id' => 'cs_somebody_elses_subscription'])->assertOk();
});

test('the success URL and the webhook agree whichever comes first', function () {
    $stripe = fakeStripe();
    $stripe->addSession('cs_both');
    $owner = User::factory()->create();
    $reservation = pendingReservation($owner, 'cs_both');

    $this->actingAs($owner)->get('/reservations/success?session_id=cs_both')
        ->assertSessionHas('status', 'reservation-complete');
    webhookFor('checkout.session.completed', ['id' => 'cs_both'])->assertOk();
    $this->actingAs($owner)->get('/reservations/success?session_id=cs_both')
        ->assertSessionHas('status', 'reservation-complete');

    expect($reservation->fresh()->status)->toBe('active');
    expect($stripe->refundRequests)->toBeEmpty();
});

test('the success URL still works after the webhook already fulfilled the pass', function () {
    $stripe = fakeStripe();
    $stripe->addSession('cs_pass2');
    $owner = User::factory()->create();
    $purchase = pendingPurchase($owner, 'cs_pass2');

    webhookFor('checkout.session.completed', ['id' => 'cs_pass2'])->assertOk();

    $this->actingAs($owner)->get('/subscriptions/success?session_id=cs_pass2')
        ->assertSessionHas('status', 'purchase-complete');
    expect($purchase->fresh()->status)->toBe('active');
});

test('a payment for a slot someone else already took is cancelled and refunded', function () {
    $stripe = fakeStripe();
    $stripe->addSession('cs_late');
    $start = now()->addDays(3)->setTime(14, 0);
    makeReservation(User::factory()->create(), $start, $start->copy()->addHour());
    $late = pendingReservation(User::factory()->create(), 'cs_late', $start, ['price_cents' => 3000]);

    webhookFor('checkout.session.completed', ['id' => 'cs_late'])->assertOk();

    $late->refresh();
    expect($late->status)->toBe('cancelled');
    expect($late->payment_status)->toBe('refunded');
    expect($late->refunded_cents)->toBe(3000);
    expect($stripe->distinctRefundCount())->toBe(1);
});

test('a payment that arrives after the customer cancelled the pending reservation is refunded', function () {
    $stripe = fakeStripe();
    $stripe->addSession('cs_paid_after_cancel');
    $reservation = pendingReservation(User::factory()->create(), 'cs_paid_after_cancel', null, [
        'status' => 'cancelled',
        'price_cents' => 3000,
    ]);

    webhookFor('checkout.session.completed', ['id' => 'cs_paid_after_cancel'])->assertOk();
    webhookFor('checkout.session.completed', ['id' => 'cs_paid_after_cancel'])->assertOk();

    $reservation->refresh();
    expect($reservation->status)->toBe('cancelled');
    expect($reservation->payment_status)->toBe('refunded');
    expect($stripe->distinctRefundCount())->toBe(1);
});

test('the success URL ignores a session that belongs to someone else', function () {
    $stripe = fakeStripe();
    $stripe->addSession('cs_theirs');
    $victim = User::factory()->create();
    $attacker = User::factory()->create();
    $reservation = pendingReservation($victim, 'cs_theirs');

    $this->actingAs($attacker)->get('/reservations/success?session_id=cs_theirs')
        ->assertSessionHas('status', 'reservation-incomplete');

    expect($reservation->fresh()->status)->toBe('pending');
});

test('the subscriptions success URL ignores a pass that belongs to someone else', function () {
    $stripe = fakeStripe();
    $stripe->addSession('cs_theirs_pass');
    $victim = User::factory()->create();
    $attacker = User::factory()->create();
    $purchase = pendingPurchase($victim, 'cs_theirs_pass');

    $this->actingAs($attacker)->get('/subscriptions/success?session_id=cs_theirs_pass')
        ->assertSessionHas('status', 'purchase-incomplete');

    expect($purchase->fresh()->status)->toBe('pending');
});

test('a recurring subscription checkout only counts for the customer it was created for', function () {
    $stripe = fakeStripe();
    $stripe->addSession('cs_sub', ['customer' => 'cus_owner', 'mode' => 'subscription']);
    $owner = User::factory()->create(['stripe_id' => 'cus_owner']);
    $other = User::factory()->create(['stripe_id' => 'cus_other']);

    $this->actingAs($other)->get('/subscriptions/success?session_id=cs_sub')
        ->assertSessionHas('status', 'purchase-incomplete');
    $this->actingAs($owner)->get('/subscriptions/success?session_id=cs_sub')
        ->assertSessionHas('status', 'purchase-complete');
});

test('the success URLs do nothing without a session id', function () {
    $this->actingAs(User::factory()->create())->get('/reservations/success')
        ->assertSessionHas('status', 'reservation-incomplete');
    $this->actingAs(User::factory()->create())->get('/subscriptions/success')
        ->assertSessionHas('status', 'purchase-incomplete');
});

/*
| Anything that comes back from Stripe's cancel_url is a plain GET: a link, a
| prefetch or a crawler can trigger it, so it must never change state.
*/

test('leaving Stripe by its back link changes nothing', function () {
    $owner = User::factory()->create();
    $purchase = pendingPurchase($owner, 'cs_back');
    $reservation = pendingReservation($owner, 'cs_back_res');

    $this->actingAs($owner)->get('/subscriptions/checkout-cancelled?session_id=cs_back')
        ->assertRedirect(route('subscriptions.index'))
        ->assertSessionHas('status', 'purchase-cancelled');
    $this->actingAs($owner)->get('/reservations/checkout-cancelled')
        ->assertRedirect(route('reservations.index'));

    expect($purchase->fresh()->status)->toBe('pending');
    expect($reservation->fresh()->status)->toBe('pending');
});

test('the old state-changing GET cancel URLs are gone', function () {
    $owner = User::factory()->create();
    $reservation = pendingReservation($owner, 'cs_old');

    $this->actingAs($owner)->get("/reservations/{$reservation->id}/cancel")->assertNotFound();
    $this->actingAs($owner)->get('/subscriptions/cancel?session_id=cs_old')->assertNotFound();

    expect($reservation->fresh()->status)->toBe('pending');
});
