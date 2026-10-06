<?php

use App\Filament\Widgets\RevenueStats;
use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Payments\CheckoutFulfillment;
use App\Support\Payments\Refunds;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| Stripe's webhook, not the customer's browser, is the source of truth for
| "this checkout was paid". These cover that lifecycle end to end.
*/

function completedCheckoutWebhook(string $sessionId, string $paymentIntent = 'pi_test_1'): void
{
    config(['cashier.webhook.secret' => null]);

    test()->postJson('/stripe/webhook', [
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => $sessionId,
            'payment_status' => 'paid',
            'payment_intent' => $paymentIntent,
        ]],
    ])->assertOk();
}

function pendingPass(User $user, string $sessionId, int $visits = 5): Purchase
{
    return Purchase::create([
        'user_id' => $user->id,
        'subscription_type_id' => makeSubscriptionType(['visit_limit' => $visits])->id,
        'stripe_checkout_session_id' => $sessionId,
        'price_cents' => 500,
        'status' => 'pending',
    ]);
}

test('paying activates a pass even if the browser never returns to the success URL', function () {
    $purchase = pendingPass(User::factory()->create(), 'cs_pass');

    completedCheckoutWebhook('cs_pass', 'pi_pass');

    expect($purchase->fresh())
        ->status->toBe('active')
        ->visits_remaining->toBe(5)
        ->payment_status->toBe('paid')
        ->stripe_payment_intent_id->toBe('pi_pass');
});

test('paying activates a reservation even if the browser never returns to the success URL', function () {
    $reservation = makeReservation(User::factory()->create(), now()->addDay(), now()->addDay()->addHour(), [
        'status' => 'pending',
        'stripe_checkout_session_id' => 'cs_res',
    ]);

    completedCheckoutWebhook('cs_res', 'pi_res');

    expect($reservation->fresh())
        ->status->toBe('active')
        ->payment_status->toBe('paid')
        ->stripe_payment_intent_id->toBe('pi_res');
});

test('a repeated webhook does not hand the pass its visits back', function () {
    $purchase = pendingPass(User::factory()->create(), 'cs_once', visits: 2);

    completedCheckoutWebhook('cs_once');
    $purchase->update(['visits_remaining' => 0, 'status' => 'used_up']);
    completedCheckoutWebhook('cs_once');

    expect($purchase->fresh())->visits_remaining->toBe(0)->status->toBe('used_up');
});

test('a checkout session for something that is not a pass or reservation is ignored', function () {
    completedCheckoutWebhook('cs_subscription_checkout');

    expect(Purchase::count())->toBe(0)->and(Reservation::count())->toBe(0);
});

test('when two overlapping reservations are both paid, only the first activates and the second is refunded', function () {
    $stripe = fakeStripe();
    $first = makeReservation(User::factory()->create(), now()->addDay(), now()->addDay()->addHour(), [
        'status' => 'pending', 'stripe_checkout_session_id' => 'cs_first',
    ]);
    $second = makeReservation(User::factory()->create(), now()->addDay()->addMinutes(30), now()->addDay()->addMinutes(90), [
        'status' => 'pending', 'stripe_checkout_session_id' => 'cs_second',
    ]);

    completedCheckoutWebhook('cs_first', 'pi_first');
    completedCheckoutWebhook('cs_second', 'pi_second');

    expect($first->fresh()->status)->toBe('active');
    expect($second->fresh())
        ->status->toBe('cancelled')
        ->payment_status->toBe('refunded')
        ->refunded_cents->toBe(3000);
    expect(array_column($stripe->refunds, 'payment_intent'))->toBe(['pi_second']);
});

test('the database itself refuses two overlapping active reservations', function () {
    makeReservation(User::factory()->create(), now()->addDay(), now()->addDay()->addHour());

    expect(fn () => makeReservation(User::factory()->create(), now()->addDay()->addMinutes(30), now()->addDay()->addMinutes(90)))
        ->toThrow(QueryException::class, 'overlapping active reservation');

    $pending = makeReservation(User::factory()->create(), now()->addDay()->addMinutes(30), now()->addDay()->addMinutes(90), ['status' => 'pending']);

    expect(fn () => $pending->update(['status' => 'active']))->toThrow(QueryException::class);
});

test('a payment that lands after the customer already cancelled the pending reservation is refunded', function () {
    $stripe = fakeStripe();
    $reservation = makeReservation(User::factory()->create(), now()->addDay(), now()->addDay()->addHour(), [
        'status' => 'cancelled', 'stripe_checkout_session_id' => 'cs_late',
    ]);

    completedCheckoutWebhook('cs_late', 'pi_late');

    expect($reservation->fresh())->status->toBe('cancelled')->payment_status->toBe('refunded');
    expect($stripe->refunds)->toHaveCount(1);
});

test('the success URL activates the reservation for its owner without waiting for the webhook', function () {
    fakeStripe(['cs_return' => ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_return']]);
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour(), [
        'status' => 'pending', 'stripe_checkout_session_id' => 'cs_return',
    ]);

    $this->actingAs($owner)->get('/reservations/success?session_id=cs_return')
        ->assertSessionHas('status', 'reservation-complete');

    expect($reservation->fresh())->status->toBe('active')->payment_status->toBe('paid');
});

test('someone else cannot use a stranger\'s checkout session id on the reservation success URL', function () {
    fakeStripe(['cs_theirs' => ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_theirs']]);
    $reservation = makeReservation(User::factory()->create(), now()->addDay(), now()->addDay()->addHour(), [
        'status' => 'pending', 'stripe_checkout_session_id' => 'cs_theirs',
    ]);

    $this->actingAs(User::factory()->create())->get('/reservations/success?session_id=cs_theirs')
        ->assertSessionHas('status', 'reservation-incomplete');

    expect($reservation->fresh())->status->toBe('pending')->payment_status->toBe('unpaid');
});

test('someone else cannot use a stranger\'s checkout session id on the pass success URL', function () {
    fakeStripe(['cs_pass_theirs' => ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_x']]);
    $purchase = pendingPass(User::factory()->create(), 'cs_pass_theirs');

    $this->actingAs(User::factory()->create())->get('/subscriptions/success?session_id=cs_pass_theirs')
        ->assertSessionHas('status', 'purchase-incomplete');

    expect($purchase->fresh())->status->toBe('pending');
});

test('state-changing reservation cancellation is no longer reachable with GET', function () {
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDays(3), now()->addDays(3)->addHour());

    $this->actingAs($owner)->get("/reservations/{$reservation->id}/cancel")->assertNotFound();

    expect($reservation->fresh()->status)->toBe('active');
});

test('cancelling inside the refund window refunds the money and records it', function () {
    makeReservationSettings(['cancellation_cutoff_hours' => 24]);
    $stripe = fakeStripe();
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDays(3), now()->addDays(3)->addHour(), ['stripe_payment_intent_id' => 'pi_cancel']);

    $this->actingAs($owner)->delete("/reservations/{$reservation->id}")
        ->assertSessionHas('status', 'reservation-cancelled-refunded');

    expect($reservation->fresh())
        ->status->toBe('cancelled')
        ->payment_status->toBe('refunded')
        ->refunded_cents->toBe(3000);
    expect($stripe->refunds)->toHaveCount(1);
});

test('a failed Stripe refund leaves a visible refund_failed record instead of silently keeping the money, and is retried', function () {
    makeReservationSettings(['cancellation_cutoff_hours' => 24]);
    $stripe = fakeStripe(failRefunds: true);
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDays(3), now()->addDays(3)->addHour());

    $this->actingAs($owner)->delete("/reservations/{$reservation->id}")
        ->assertSessionHas('status', 'reservation-cancelled-refund-pending');

    expect($reservation->fresh())->status->toBe('cancelled')->payment_status->toBe('refund_failed');

    // Stripe comes back; the scheduled retry settles it.
    $stripe->failRefunds = false;
    $this->travel(2)->minutes();
    $this->artisan('payments:retry-refunds')->assertSuccessful();

    expect($reservation->fresh())->payment_status->toBe('refunded')->refunded_cents->toBe(3000);
});

test('an admin refund of a pass records the refund', function () {
    $stripe = fakeStripe();
    $purchase = pendingPass(User::factory()->create(), 'cs_admin_refund');
    completedCheckoutWebhook('cs_admin_refund', 'pi_admin_refund');

    expect(app(Refunds::class)->refundPurchase($purchase->fresh()))->toBeTrue();

    expect($purchase->fresh())->status->toBe('refunded')->payment_status->toBe('refunded')->refunded_cents->toBe(500);
    expect($stripe->refunds)->toHaveCount(1);
});

test('revenue keeps counting a paid reservation that was cancelled too late for a refund, and drops a refunded one', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $owner = User::factory()->create();
    makeReservation($owner, now()->addDay(), now()->addDay()->addHour(), [
        'status' => 'cancelled', 'payment_status' => 'paid', 'price_cents' => 4100,
    ]);
    makeReservation($owner, now()->addDays(2), now()->addDays(2)->addHour(), [
        'status' => 'cancelled', 'payment_status' => 'refunded', 'price_cents' => 7700, 'refunded_cents' => 7700,
    ]);

    $this->actingAs($admin);

    Livewire::test(RevenueStats::class)
        ->assertSee('41.00 €')
        ->assertSee('77.00 €'); // only in the "Refunded" stat
    expect(app(CheckoutFulfillment::class))->toBeInstanceOf(CheckoutFulfillment::class);
});

test('a second simultaneous subscription checkout click is refused while the first is being created', function () {
    $user = User::factory()->create();
    $plan = makeSubscriptionType(['billing_interval' => 'month', 'price_cents' => 2500]);
    DB::table('subscription_types')->where('id', $plan->id)->update(['stripe_price_id' => 'price_x', 'stripe_product_id' => 'prod_x']);

    // Another request for this customer is mid-way through creating a session.
    $held = Cache::lock("subscription-checkout:{$user->id}", 30);
    expect($held->get())->toBeTrue();

    $this->actingAs($user)->post("/subscriptions/{$plan->id}/checkout")->assertStatus(429);
});

test('a plan that would sell zero visits cannot be checked out even if it is active', function () {
    $user = User::factory()->create();
    $broken = makeSubscriptionType(['visit_limit' => null, 'unlimited_entries' => false]);
    DB::table('subscription_types')->where('id', $broken->id)->update(['stripe_price_id' => 'price_broken']);

    $this->actingAs($user)->post("/subscriptions/{$broken->id}/checkout")->assertNotFound();
    expect(Purchase::count())->toBe(0);
});

test('booking creates a pending reservation and sends the customer to Stripe', function () {
    makeReservationSettings();
    $stripe = fakeStripe();
    $customer = User::factory()->create();
    $start = now()->addDays(2)->setTime(12, 0);

    $response = $this->actingAs($customer)->post('/reservations', [
        'starts_at' => $start->format('Y-m-d H:i'), 'duration_minutes' => 60, 'group_size' => 3,
    ]);

    $response->assertRedirect('https://checkout.stripe.test/pay/1');
    $reservation = Reservation::firstOrFail();
    expect($reservation)->status->toBe('pending')->payment_status->toBe('unpaid')->stripe_checkout_session_id->toBe('cs_fake_1');
    expect($stripe->checkouts)->toBe([['amount' => 1500, 'user' => $customer->id]]);
});
