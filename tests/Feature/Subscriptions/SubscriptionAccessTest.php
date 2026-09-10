<?php

use App\Models\SubscriptionType;
use App\Models\User;

function makeRecurringPlan(array $attributes = []): SubscriptionType
{
    // stripe_product_id/stripe_price_id aren't mass-fillable, and
    // withoutEvents() skips the `saved` hook that would normally set them
    // via a real Stripe API call, so they're forced in directly here.
    return SubscriptionType::withoutEvents(function () use ($attributes) {
        $type = SubscriptionType::create(array_merge([
            'name' => 'Monthly',
            'price_cents' => 2000,
            'billing_interval' => 'month',
            'active' => true,
        ], $attributes));

        $type->forceFill([
            'stripe_product_id' => 'prod_test',
            'stripe_price_id' => 'price_test',
        ])->save();

        return $type;
    });
}

test('unverified users cannot view subscriptions', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get('/subscriptions');

    $response->assertRedirect(route('verification.notice'));
});

test('verified users can view subscriptions', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/subscriptions');

    $response->assertOk();
});

test('employees are redirected away from subscriptions', function () {
    $employee = User::factory()->create(['role' => 'employee']);

    $response = $this->actingAs($employee)->get('/subscriptions');

    $response->assertRedirect(route('staff.scan', absolute: false));
});

test('admins are redirected away from subscriptions', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get('/subscriptions');

    $response->assertRedirect('/admin');
});

test('a user cannot start a second recurring subscription while one is active', function () {
    $user = User::factory()->create();
    $plan = makeRecurringPlan();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test',
        'quantity' => 1,
    ]);

    $response = $this->actingAs($user)->post("/subscriptions/{$plan->id}/checkout");

    $response->assertStatus(409);
});
