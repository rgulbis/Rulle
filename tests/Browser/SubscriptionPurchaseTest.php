<?php

use App\Models\Purchase;
use App\Models\User;

test('a customer buys a day pass', function () {
    $stripe = fakeStripe();
    $plan = makeSubscriptionType([
        'name' => 'Day pass',
        'price_cents' => 800,
        'visit_limit' => 3,
    ]);
    $plan->forceFill(['stripe_price_id' => 'price_day'])->saveQuietly();
    $customer = User::factory()->create();

    $this->actingAs($customer);

    // The customer opens the price list and chooses the plan.
    $page = visit('/subscriptions')
        ->assertSee('Day pass')
        ->click('Pirkt')
        ->wait(1);

    $purchase = Purchase::firstWhere('user_id', $customer->id);
    expect($stripe->createdCheckouts)->toHaveCount(1);
    expect($purchase->status)->toBe('pending');

    // Stripe confirms the payment and sends the customer back to the site.
    $stripe->addSession($purchase->stripe_checkout_session_id);

    $page->navigate('/subscriptions/success?session_id='.$purchase->stripe_checkout_session_id)
        ->assertSee('Pirkums pabeigts - jūsu piekļuve tagad ir aktīva.')
        ->assertSee('Atlikuši 3 apmeklējumi')
        ->assertNoJavaScriptErrors();

    expect($purchase->fresh()->status)->toBe('active');
});
