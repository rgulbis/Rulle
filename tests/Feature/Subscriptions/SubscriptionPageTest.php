<?php

use App\Models\Purchase;
use App\Models\User;

test('the plans page sends the browser only what a price card needs', function () {
    makeRecurringPlan(['name' => 'Monthly pass']);

    $this->actingAs(User::factory()->create())->get('/subscriptions')
        ->assertInertia(fn ($page) => $page
            ->component('subscriptions/index')
            ->has('plans', 1, fn ($plan) => $plan
                ->hasAll(['id', 'name', 'name_lv', 'description', 'description_lv', 'price_cents', 'billing_interval', 'visit_limit', 'unlimited_entries'])
                ->missingAll(['stripe_product_id', 'stripe_price_id', 'stripe_synced_name', 'stripe_synced_price_cents', 'stripe_synced_interval', 'created_at', 'updated_at', 'active'])
            ));
});

test('the plans page shows an active pass without exposing the purchase row', function () {
    $user = User::factory()->create();
    $plan = makeSubscriptionType(['visit_limit' => 3]);
    Purchase::create([
        'user_id' => $user->id,
        'subscription_type_id' => $plan->id,
        'stripe_checkout_session_id' => 'cs_secret_session',
        'price_cents' => $plan->price_cents,
        'status' => 'active',
        'payment_status' => 'paid',
        'visits_remaining' => 2,
        'valid_date' => today(),
    ]);

    $this->actingAs($user)->get('/subscriptions')
        ->assertInertia(fn ($page) => $page
            ->where('activePurchase.visits_remaining', 2)
            ->where('activePurchase.subscription_type.unlimited_entries', false)
            ->missing('activePurchase.stripe_checkout_session_id')
            ->missing('activePurchase.user_id')
            ->missing('activePurchase.subscription_type.stripe_price_id')
        );
});

test('without a pass, activePurchase is null', function () {
    $this->actingAs(User::factory()->create())->get('/subscriptions')
        ->assertInertia(fn ($page) => $page->where('activePurchase', null));
});
