<?php

use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Models\Purchase;
use App\Models\User;
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
        'payment_status' => ($attributes['status'] ?? 'active') === 'pending' ? 'unpaid' : 'paid',
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
