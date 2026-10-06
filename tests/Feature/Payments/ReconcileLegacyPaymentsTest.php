<?php

use App\Models\Purchase;
use App\Models\User;

/*
| Old cancelled reservations can't be classified from local data alone
| (refunded vs. kept vs. never paid) — payments:reconcile-legacy asks Stripe.
*/

function legacyCancelled(string $session, int $price = 3000)
{
    return makeReservation(User::factory()->create(), now()->subDays(20), now()->subDays(20)->addHour(), [
        'status' => 'cancelled', 'payment_status' => 'unpaid', 'stripe_payment_intent_id' => null, 'paid_at' => null,
        'stripe_checkout_session_id' => $session, 'price_cents' => $price,
    ]);
}

test('it classifies old cancelled reservations from what Stripe says', function () {
    $stripe = fakeStripe([
        'cs_never' => ['status' => 'expired', 'payment_status' => 'unpaid'],
        'cs_kept' => ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_kept'],
        'cs_refunded' => ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_refunded'],
    ]);
    $stripe->refundedByIntent['pi_refunded'] = 3000;

    $never = legacyCancelled('cs_never');
    $kept = legacyCancelled('cs_kept');
    $refunded = legacyCancelled('cs_refunded');

    // Dry run changes nothing.
    $this->artisan('payments:reconcile-legacy')->assertSuccessful();
    expect($never->fresh()->payment_status)->toBe('unpaid');
    expect($kept->fresh()->payment_status)->toBe('unpaid');

    $this->artisan('payments:reconcile-legacy', ['--apply' => true])->assertSuccessful();

    expect($never->fresh()->payment_status)->toBe('unpaid');
    // Paid and not refunded: the business kept this money, so revenue must count it.
    expect($kept->fresh())->payment_status->toBe('paid')->refunded_cents->toBe(0)->stripe_payment_intent_id->toBe('pi_kept');
    expect($refunded->fresh())->payment_status->toBe('refunded')->refunded_cents->toBe(3000);
});

test('an abandoned pass that was actually paid is activated', function () {
    fakeStripe(['cs_paid_pass' => ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_pass']]);
    $purchase = Purchase::create([
        'user_id' => User::factory()->create()->id,
        'subscription_type_id' => makeSubscriptionType(['visit_limit' => 4])->id,
        'stripe_checkout_session_id' => 'cs_paid_pass',
        'price_cents' => 500,
        'status' => 'abandoned',
    ]);

    $this->artisan('payments:reconcile-legacy', ['--apply' => true])->assertSuccessful();

    expect($purchase->fresh())->status->toBe('active')->visits_remaining->toBe(4)->payment_status->toBe('paid');
});

test('a Stripe error is reported and leaves the row alone', function () {
    $stripe = fakeStripe();
    $stripe->failCheckoutSessions = true;
    $row = legacyCancelled('cs_error');

    $this->artisan('payments:reconcile-legacy', ['--apply' => true])->assertFailed();

    expect($row->fresh()->payment_status)->toBe('unpaid');
});
