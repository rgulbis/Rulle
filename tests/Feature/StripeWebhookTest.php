<?php

use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/*
| A checkout a customer starts but never finishes - closes the tab instead
| of clicking Stripe's own back/cancel link - left its Purchase or
| Reservation row `pending` forever. checkout.session.expired is Stripe's
| own signal that an unfinished session is truly dead, not just slow.
*/

function postStripeWebhook(array $payload): TestResponse
{
    // Disables VerifyWebhookSignature for this request: a real deployment
    // (and a developer's own .env, for `stripe listen`) has a webhook
    // secret configured, but signing a payload to match it isn't something
    // worth reproducing just to test the handler's own logic.
    config(['cashier.webhook.secret' => null]);

    return test()->postJson('/stripe/webhook', $payload);
}

test('an expired checkout session abandons its pending one-time pass', function () {
    $user = User::factory()->create();
    $type = makeSubscriptionType();
    $purchase = Purchase::create([
        'user_id' => $user->id,
        'subscription_type_id' => $type->id,
        'stripe_checkout_session_id' => 'cs_test_expired',
        'price_cents' => 500,
        'status' => 'pending',
    ]);

    postStripeWebhook([
        'type' => 'checkout.session.expired',
        'data' => ['object' => ['id' => 'cs_test_expired']],
    ])->assertOk();

    expect($purchase->fresh()->status)->toBe('abandoned');
});

test('an expired checkout session cancels its pending reservation', function () {
    $user = User::factory()->create();
    $reservation = makeReservation($user, now()->addDay(), now()->addDay()->addHour(), [
        'status' => 'pending',
        'stripe_checkout_session_id' => 'cs_test_expired_res',
    ]);

    postStripeWebhook([
        'type' => 'checkout.session.expired',
        'data' => ['object' => ['id' => 'cs_test_expired_res']],
    ])->assertOk();

    expect($reservation->fresh()->status)->toBe('cancelled');
});

test('an expired checkout session leaves an already-completed purchase alone', function () {
    $user = User::factory()->create();
    $type = makeSubscriptionType();
    $purchase = Purchase::create([
        'user_id' => $user->id,
        'subscription_type_id' => $type->id,
        'stripe_checkout_session_id' => 'cs_test_done',
        'price_cents' => 500,
        'status' => 'active',
    ]);

    postStripeWebhook([
        'type' => 'checkout.session.expired',
        'data' => ['object' => ['id' => 'cs_test_done']],
    ])->assertOk();

    expect($purchase->fresh()->status)->toBe('active');
});
