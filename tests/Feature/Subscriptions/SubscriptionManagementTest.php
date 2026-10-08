<?php

use App\Models\Purchase;
use App\Models\SubscriptionType;
use App\Models\User;

function subscriberOn(SubscriptionType $plan, array $attributes = []): User
{
    $user = User::factory()->create(['stripe_id' => 'cus_'.str()->random(6)]);

    $user->subscriptions()->create(array_merge([
        'type' => 'default',
        'stripe_id' => 'sub_'.str()->random(6),
        'stripe_status' => 'active',
        'stripe_price' => $plan->stripe_price_id,
        'quantity' => 1,
    ], $attributes));

    return $user;
}

function activePass(User $user, SubscriptionType $plan): Purchase
{
    return Purchase::create([
        'user_id' => $user->id,
        'subscription_type_id' => $plan->id,
        'stripe_checkout_session_id' => 'cs_'.str()->random(8),
        'price_cents' => $plan->price_cents,
        'status' => 'active',
        'payment_status' => 'paid',
        'visits_remaining' => 2,
        'valid_date' => today(),
    ]);
}

// --- Resume ----------------------------------------------------------------

test('a cancelled subscription still in its paid period can be resumed', function () {
    $stripe = fakeStripe();
    $user = subscriberOn(makeRecurringPlan(), ['ends_at' => now()->addDays(10)]);
    $subscription = $user->subscription('default');

    $this->actingAs($user)->post('/subscriptions/subscription/resume')
        ->assertRedirect(route('subscriptions.index'))
        ->assertSessionHas('status', 'subscription-resumed');

    expect($subscription->fresh()->ends_at)->toBeNull()
        ->and($subscription->fresh()->canceled())->toBeFalse()
        ->and($stripe->resumedSubscriptions)->toBe([$subscription->stripe_id]);
});

test('resuming twice only tells Stripe once', function () {
    $stripe = fakeStripe();
    $user = subscriberOn(makeRecurringPlan(), ['ends_at' => now()->addDays(10)]);

    $this->actingAs($user)->post('/subscriptions/subscription/resume');
    $this->actingAs($user)->post('/subscriptions/subscription/resume')
        ->assertSessionHas('status', 'subscription-resumed');

    expect($stripe->resumedSubscriptions)->toHaveCount(1);
});

test('a subscription that has already ended cannot be resumed', function () {
    $stripe = fakeStripe();
    $user = subscriberOn(makeRecurringPlan(), ['ends_at' => now()->subDay(), 'stripe_status' => 'canceled']);

    $this->actingAs($user)->post('/subscriptions/subscription/resume')->assertNotFound();

    expect($stripe->resumedSubscriptions)->toBeEmpty();
});

test('someone with no subscription has nothing to resume', function () {
    $stripe = fakeStripe();

    $this->actingAs(User::factory()->create())->post('/subscriptions/subscription/resume')->assertNotFound();

    expect($stripe->resumedSubscriptions)->toBeEmpty();
});

test('resume is for customers, and guests are sent to log in', function () {
    $this->post('/subscriptions/subscription/resume')->assertRedirect('/login');
    $this->actingAs(User::factory()->create(['role' => 'employee']))
        ->post('/subscriptions/subscription/resume')->assertRedirect(route('staff.scan', absolute: false));
});

test('the page offers resume only while a cancelled subscription is still running', function () {
    $user = subscriberOn(makeRecurringPlan(), ['ends_at' => now()->addDays(10)]);

    $this->actingAs($user)->get('/subscriptions')
        ->assertInertia(fn ($page) => $page
            ->where('activeSubscription.canceled', true)
            ->where('activeSubscription.on_grace_period', true));
});

// --- One pass at a time ----------------------------------------------------

test('a one-time pass cannot be bought while another is still usable', function () {
    $stripe = fakeStripe();
    $user = User::factory()->create();
    $plan = makeSubscriptionType(['visit_limit' => 3]);
    $plan->forceFill(['stripe_product_id' => 'prod_x', 'stripe_price_id' => 'price_x'])->saveQuietly();
    activePass($user, $plan);

    $this->actingAs($user)->post("/subscriptions/{$plan->id}/checkout")
        ->assertRedirect(route('subscriptions.index'))
        ->assertSessionHas('status', 'pass-already-active');

    expect($stripe->createdCheckouts)->toBeEmpty()
        ->and(Purchase::where('user_id', $user->id)->where('status', 'pending')->count())->toBe(0);
});

test('a used-up pass does not stand in the way of buying another', function () {
    $stripe = fakeStripe();
    $user = User::factory()->create();
    $plan = makeSubscriptionType(['visit_limit' => 3]);
    $plan->forceFill(['stripe_product_id' => 'prod_x', 'stripe_price_id' => 'price_x'])->saveQuietly();
    activePass($user, $plan)->update(['status' => 'used_up', 'visits_remaining' => 0]);

    $this->actingAs($user)->post("/subscriptions/{$plan->id}/checkout")->assertRedirect();

    expect($stripe->createdCheckouts)->toHaveCount(1);
});
