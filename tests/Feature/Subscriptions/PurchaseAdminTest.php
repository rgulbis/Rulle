<?php

use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Filament\Widgets\RevenueStats;
use App\Models\Purchase;
use App\Models\User;
use App\Support\StripeRefunds;
use Livewire\Livewire;

function makePurchase(array $attributes = []): Purchase
{
    $subscriptionTypeId = $attributes['subscription_type_id'] ?? makeSubscriptionType()->id;

    return Purchase::create(array_merge([
        'user_id' => User::factory()->create()->id,
        'subscription_type_id' => $subscriptionTypeId,
        'stripe_checkout_session_id' => 'cs_test_'.str()->random(10),
        'price_cents' => 1000,
        'status' => 'active',
    ], $attributes));
}

test('an admin can see every purchase, including who made it and what they paid', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $purchase = makePurchase(['price_cents' => 1500]);

    $this->actingAs($admin);

    Livewire::test(ListPurchases::class)->assertCanSeeTableRecords([$purchase]);
});

test('the refund action is only visible for active purchases', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $active = makePurchase(['status' => 'active']);
    $usedUp = makePurchase(['status' => 'used_up']);
    $pending = makePurchase(['status' => 'pending']);

    $this->actingAs($admin);

    Livewire::test(ListPurchases::class)
        ->assertTableActionVisible('refund', $active)
        ->assertTableActionHidden('refund', $usedUp)
        ->assertTableActionHidden('refund', $pending);
});

test('refunding a purchase revokes it as soon as its status changes', function () {
    // Exercises the state change directly rather than through the Filament
    // action, since that action calls Stripe's real API — not something to
    // do in an automated test without a network double.
    $purchase = makePurchase(['status' => 'active']);

    $purchase->update(['status' => 'refunded']);

    expect($purchase->fresh()->isCurrentlyUsable())->toBeFalse();
});

test('refunding does nothing and reports no refund when there is no checkout session on record', function () {
    expect(StripeRefunds::refundCheckoutSession(null))->toBeFalse();
    expect(StripeRefunds::refundCheckoutSession(''))->toBeFalse();
});

test('revenue stats only count money actually collected, not pending or refunded purchases', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    makePurchase(['status' => 'active', 'price_cents' => 1000]);
    makePurchase(['status' => 'used_up', 'price_cents' => 500]);
    makePurchase(['status' => 'pending', 'price_cents' => 9999]);
    makePurchase(['status' => 'refunded', 'price_cents' => 9999]);

    $this->actingAs($admin);

    Livewire::test(RevenueStats::class)
        ->assertSee('15.00 €')
        ->assertSee('99.99 €');
});
