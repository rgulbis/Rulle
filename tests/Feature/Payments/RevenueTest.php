<?php

use App\Filament\Widgets\RevenueStats;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| Revenue is what was paid minus what was refunded, read from
| `payment_status` - not from whether a booking is still active.
*/

function revenuePurchase(array $attributes): Purchase
{
    return Purchase::create(array_merge([
        'user_id' => User::factory()->create()->id,
        'subscription_type_id' => makeSubscriptionType()->id,
        'stripe_checkout_session_id' => 'cs_rev_'.str()->random(10),
        'price_cents' => 1000,
        'status' => 'active',
        'payment_status' => 'paid',
    ], $attributes));
}

function revenueStat(string $label): string
{
    $html = Livewire::test(RevenueStats::class)->html();
    preg_match('/'.preg_quote($label, '/').'\s*<\/span>.*?fi-wi-stats-overview-stat-value">\s*([\d.,]+ €)/s', $html, $matches);

    return $matches[1] ?? "(no stat named {$label})";
}

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $this->owner = User::factory()->create();
});

test('paid passes count, unpaid and abandoned ones do not', function () {
    revenuePurchase(['price_cents' => 1000]);
    revenuePurchase(['price_cents' => 500, 'status' => 'used_up']);
    revenuePurchase(['price_cents' => 9999, 'status' => 'pending', 'payment_status' => 'unpaid']);
    revenuePurchase(['price_cents' => 8888, 'status' => 'abandoned', 'payment_status' => 'unpaid']);

    expect(revenueStat('One-time passes'))->toBe('15.00 €');
});

test('a refunded pass nets out to nothing and shows up under refunds', function () {
    revenuePurchase(['price_cents' => 1000]);
    revenuePurchase([
        'price_cents' => 4000,
        'status' => 'cancelled',
        'payment_status' => 'refunded',
        'refunded_cents' => 4000,
    ]);

    expect(revenueStat('One-time passes'))->toBe('10.00 €');
    expect(revenueStat('Refunded'))->toBe('40.00 €');
});

test('a partial refund only takes back the refunded part', function () {
    revenuePurchase([
        'price_cents' => 4000,
        'payment_status' => 'partially_refunded',
        'refunded_cents' => 1500,
    ]);

    expect(revenueStat('One-time passes'))->toBe('25.00 €');
});

test('a reservation cancelled too late for a refund still counts as revenue', function () {
    makeReservation($this->owner, now()->addHours(2), now()->addHours(3), [
        'status' => 'cancelled',
        'payment_status' => 'paid',
        'price_cents' => 3000,
    ]);

    expect(revenueStat('Reservations'))->toBe('30.00 €');
});

test('a refunded reservation does not count, an unpaid one never did', function () {
    makeReservation($this->owner, now()->addDays(2), now()->addDays(2)->addHour(), [
        'status' => 'cancelled',
        'payment_status' => 'refunded',
        'price_cents' => 3000,
        'refunded_cents' => 3000,
    ]);
    makeReservation($this->owner, now()->addDays(3), now()->addDays(3)->addHour(), ['status' => 'pending', 'price_cents' => 7000]);
    makeReservation($this->owner, now()->addDays(4), now()->addDays(4)->addHour(), ['price_cents' => 1200]);

    expect(revenueStat('Reservations'))->toBe('12.00 €');
    expect(revenueStat('Refunded'))->toBe('30.00 €');
});

test('a refund that failed at Stripe still counts as money kept until it succeeds', function () {
    makeReservation($this->owner, now()->addDays(2), now()->addDays(2)->addHour(), [
        'status' => 'cancelled',
        'payment_status' => 'refund_failed',
        'price_cents' => 3000,
    ]);

    expect(revenueStat('Reservations'))->toBe('30.00 €');
});

/*
| T1.7: what a subscriber is billed is stored on their subscription.
*/

function subscriptionEvent(string $type, int $unitAmount, string $subscriptionId = 'sub_billed'): array
{
    return [
        'type' => $type,
        'data' => ['object' => [
            'id' => $subscriptionId,
            'customer' => 'cus_billed',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'items' => ['data' => [[
                'id' => 'si_billed',
                'quantity' => 1,
                'price' => ['id' => 'price_billed', 'product' => 'prod_billed', 'unit_amount' => $unitAmount],
            ]]],
            'metadata' => [],
        ]],
    ];
}

test('a new subscription records the amount it is billed', function () {
    config(['cashier.webhook.secret' => null]);
    User::factory()->create(['stripe_id' => 'cus_billed']);

    $this->postJson('/stripe/webhook', subscriptionEvent('customer.subscription.created', 2500))->assertOk();

    expect((int) DB::table('subscriptions')->where('stripe_id', 'sub_billed')->value('price_cents'))->toBe(2500);
});

test('swapping to a new price updates what is billed, and repricing the plan later does not rewrite it', function () {
    config(['cashier.webhook.secret' => null]);
    User::factory()->create(['stripe_id' => 'cus_billed']);
    $type = makeSubscriptionType(['name' => 'Monthly', 'billing_interval' => 'month', 'price_cents' => 2500]);
    DB::table('subscription_types')->where('id', $type->id)->update(['stripe_product_id' => 'prod_billed']);

    $this->postJson('/stripe/webhook', subscriptionEvent('customer.subscription.created', 2500))->assertOk();
    $this->postJson('/stripe/webhook', subscriptionEvent('customer.subscription.updated', 3000))->assertOk();

    // An admin reprices the plan afterwards; this subscriber keeps their bill.
    DB::table('subscription_types')->where('id', $type->id)->update(['price_cents' => 9900]);

    expect(Payment::where('type', 'subscription')->first()->amount_cents)->toBe(3000);
});

/*
| Subscription revenue is read from Stripe, but only for this app's own
| customers, net of credit notes.
*/

test('subscription revenue counts only this app\'s customers, net of credit notes', function () {
    $stripe = fakeStripe();
    User::factory()->create(['stripe_id' => 'cus_ours']);
    $closed = User::factory()->create(['stripe_id' => 'cus_closed']);
    $closed->delete();

    $stripe->addPaidInvoice('cus_ours', 3500);
    $stripe->addPaidInvoice('cus_ours', 3500, ['post_payment_credit_notes_amount' => 1000]);
    $stripe->addPaidInvoice('cus_closed', 2000);
    // Not ours: left over in the Stripe account from testing or another app.
    $stripe->addPaidInvoice('cus_stranger', 99900);

    expect(revenueStat('Subscriptions'))->toBe('80.00 €');
});

test('a fully credited invoice nets out to nothing, never below zero', function () {
    $stripe = fakeStripe();
    User::factory()->create(['stripe_id' => 'cus_ours']);

    $stripe->addPaidInvoice('cus_ours', 3500, ['post_payment_credit_notes_amount' => 5000]);

    expect(revenueStat('Subscriptions'))->toBe('0.00 €');
});

test('with no Stripe customers of its own, subscription revenue is zero', function () {
    $stripe = fakeStripe();
    $stripe->addPaidInvoice('cus_stranger', 99900);

    expect(revenueStat('Subscriptions'))->toBe('0.00 €');
});
