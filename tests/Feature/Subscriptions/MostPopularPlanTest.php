<?php

use App\Models\Purchase;
use App\Models\SubscriptionType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function sellPurchase(SubscriptionType $type, string $status = 'active'): void
{
    static $n = 0;

    Purchase::create([
        'user_id' => User::factory()->create()->id,
        'subscription_type_id' => $type->id,
        'stripe_checkout_session_id' => 'cs_popular_'.(++$n),
        'status' => $status,
        'visits_remaining' => 1,
    ]);
}

function sellSubscription(string $stripeProduct, string $status = 'active'): void
{
    static $n = 0;
    $n++;

    $subscriptionId = DB::table('subscriptions')->insertGetId([
        'user_id' => User::factory()->create()->id,
        'type' => 'default',
        'stripe_id' => "sub_popular_{$n}",
        'stripe_status' => $status,
        'stripe_price' => "price_old_{$n}",
        'quantity' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('subscription_items')->insert([
        'subscription_id' => $subscriptionId,
        'stripe_id' => "si_popular_{$n}",
        'stripe_product' => $stripeProduct,
        'stripe_price' => "price_old_{$n}",
        'quantity' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('there is no most popular plan before anything has sold', function () {
    makeSubscriptionType();

    expect(SubscriptionType::mostPopularId())->toBeNull();
});

test('the plan with the most completed sales is the most popular', function () {
    $day = makeSubscriptionType(['name' => 'Day pass']);
    $ten = makeSubscriptionType(['name' => '10 visits']);

    sellPurchase($day);
    sellPurchase($ten);
    sellPurchase($ten, 'used_up');

    expect(SubscriptionType::mostPopularId())->toBe($ten->id);
});

test('unfinished checkouts and refunds do not count as sales', function () {
    $day = makeSubscriptionType(['name' => 'Day pass']);
    $ten = makeSubscriptionType(['name' => '10 visits']);

    sellPurchase($day);
    sellPurchase($ten, 'pending');
    sellPurchase($ten, 'cancelled');

    expect(SubscriptionType::mostPopularId())->toBe($day->id);
});

test('subscriptions count by Stripe product, even on an old price', function () {
    $day = makeSubscriptionType(['name' => 'Day pass']);
    $monthly = makeSubscriptionType(['name' => 'Monthly', 'billing_interval' => 'month']);
    // Not mass-assignable — normally set by the Stripe sync.
    $monthly->forceFill(['stripe_product_id' => 'prod_monthly'])->saveQuietly();

    sellPurchase($day);
    sellSubscription('prod_monthly');
    sellSubscription('prod_monthly', 'canceled');
    // Never finished paying — not a sale.
    sellSubscription('prod_monthly', 'incomplete');

    expect(SubscriptionType::mostPopularId())->toBe($monthly->id);
});

test('a tie at the top means no badge', function () {
    $day = makeSubscriptionType(['name' => 'Day pass']);
    $ten = makeSubscriptionType(['name' => '10 visits']);

    sellPurchase($day);
    sellPurchase($ten);

    expect(SubscriptionType::mostPopularId())->toBeNull();
});

test('the passes pages are told which plan is most popular', function () {
    $ten = makeSubscriptionType(['name' => '10 visits']);
    sellPurchase($ten);

    $this->get('/')->assertInertia(fn ($page) => $page->where('mostPopularPlanId', $ten->id));
});
