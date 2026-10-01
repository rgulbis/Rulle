<?php

use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\SubscriptionType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| The `payments` table is a read-only database view (see its migration)
| unioning three independent sources — purchases, reservations, and Stripe
| subscriptions — since nothing else in the admin panel shows all of them
| as a single ledger.
*/

// stripe_product_id isn't mass-assignable (SubscriptionType::syncToStripe()
// is the only normal writer), so tests that need one set have to bypass
// Eloquent entirely, the same way other tests in this suite set other
// deliberately-unfillable columns (e.g. User::role).
function withStripeProductId(SubscriptionType $type, string $productId): SubscriptionType
{
    DB::table('subscription_types')->where('id', $type->id)->update(['stripe_product_id' => $productId]);

    return $type->fresh();
}

function makeSubscriptionRow(User $user, SubscriptionType $type, array $attributes = []): void
{
    $subscriptionId = DB::table('subscriptions')->insertGetId(array_merge([
        'user_id' => $user->id,
        'type' => 'default',
        'stripe_id' => 'sub_test_'.str()->random(10),
        'stripe_status' => 'active',
        'stripe_price' => $type->stripe_price_id ?? 'price_test',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));

    DB::table('subscription_items')->insert([
        'subscription_id' => $subscriptionId,
        'stripe_id' => 'si_test_'.str()->random(10),
        'stripe_product' => $type->stripe_product_id,
        'stripe_price' => $type->stripe_price_id ?? 'price_test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('the payments ledger combines one-time passes, reservations, and subscriptions', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = User::factory()->create();
    $passType = withStripeProductId(makeSubscriptionType(), 'prod_pass');

    $purchase = Purchase::create([
        'user_id' => $customer->id,
        'subscription_type_id' => $passType->id,
        'stripe_checkout_session_id' => 'cs_test_'.str()->random(10),
        'price_cents' => 500,
        'status' => 'active',
    ]);
    $reservation = makeReservation($customer, now()->addDay(), now()->addDay()->addHour(), ['price_cents' => 1500]);

    $planType = withStripeProductId(makeSubscriptionType([
        'name' => 'Monthly plan',
        'billing_interval' => 'month',
        'price_cents' => 3500,
    ]), 'prod_monthly');
    makeSubscriptionRow($customer, $planType);

    $this->actingAs($admin);

    $rows = Payment::all();

    expect($rows)->toHaveCount(3);
    expect($rows->pluck('type')->sort()->values()->all())->toBe(['purchase', 'reservation', 'subscription']);

    $subscriptionRow = $rows->firstWhere('type', 'subscription');
    expect($subscriptionRow->description)->toBe('Monthly plan');
    expect($subscriptionRow->amount_cents)->toBe(3500);
    expect($subscriptionRow->user_id)->toBe($customer->id);

    Livewire::test(ListPayments::class)
        ->assertCanSeeTableRecords([
            Payment::find('purchase-'.$purchase->id),
            Payment::find('reservation-'.$reservation->id),
            $subscriptionRow,
        ]);
});

test('the type filter narrows the ledger to just one source', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = User::factory()->create();
    $type = withStripeProductId(makeSubscriptionType(), 'prod_pass');

    Purchase::create([
        'user_id' => $customer->id,
        'subscription_type_id' => $type->id,
        'stripe_checkout_session_id' => 'cs_test_'.str()->random(10),
        'price_cents' => 500,
        'status' => 'active',
    ]);
    makeReservation($customer, now()->addDay(), now()->addDay()->addHour());

    $this->actingAs($admin);

    Livewire::test(ListPayments::class)
        ->filterTable('type', 'reservation')
        ->assertCountTableRecords(1);
});

test('the payments ledger is read-only', function () {
    expect(PaymentResource::canCreate())->toBeFalse();
    expect(PaymentResource::canEdit(new Payment))->toBeFalse();
    expect(PaymentResource::canDelete(new Payment))->toBeFalse();
});
