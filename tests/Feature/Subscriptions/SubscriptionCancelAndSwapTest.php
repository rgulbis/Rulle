<?php

use App\Models\SubscriptionType;
use App\Models\User;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;

/**
 * A canned Stripe API. Cashier talks to Stripe directly for cancelling and
 * swapping, so these tests answer its requests from memory: every call is
 * recorded, and the subscription Stripe "returns" is whatever the test says.
 */
final class CannedStripeApi implements ClientInterface
{
    /** @var list<array{method: string, path: string, params: array<string, mixed>}> */
    public array $requests = [];

    public function __construct(
        private readonly string $subscriptionPrice,
        private readonly int $periodEnd,
    ) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = parse_url($absUrl, PHP_URL_PATH);
        $this->requests[] = ['method' => $method, 'path' => $path, 'params' => $params ?? []];

        $body = match (true) {
            str_starts_with($path, '/v1/prices/') => $this->price(basename($path)),
            str_starts_with($path, '/v1/subscription_items/') => $this->item(),
            default => [
                'id' => 'sub_test', 'object' => 'subscription', 'status' => 'active',
                'items' => ['object' => 'list', 'data' => [$this->item()]],
            ],
        };

        return [json_encode($body), 200, []];
    }

    /** @return list<array{method: string, path: string, params: array<string, mixed>}> */
    public function writes(): array
    {
        return array_values(array_filter($this->requests, fn ($r) => $r['method'] === 'post'));
    }

    private function item(): array
    {
        return [
            'id' => 'si_test', 'object' => 'subscription_item', 'quantity' => 1,
            'current_period_end' => $this->periodEnd,
            'price' => $this->price($this->subscriptionPrice),
        ];
    }

    private function price(string $id): array
    {
        return [
            'id' => $id, 'object' => 'price', 'product' => 'prod_test', 'unit_amount' => 2000,
            'recurring' => ['interval' => 'month', 'usage_type' => 'licensed'],
        ];
    }
}

function stripeApiAnswering(string $subscriptionPrice, int $periodEnd): CannedStripeApi
{
    config(['cashier.secret' => 'sk_test_canned']);

    $api = new CannedStripeApi($subscriptionPrice, $periodEnd);
    ApiRequestor::setHttpClient($api);

    return $api;
}

function subscriberOnPrice(string $price): User
{
    $user = User::factory()->create(['stripe_id' => 'cus_test']);

    $subscription = $user->subscriptions()->create([
        'type' => 'default', 'stripe_id' => 'sub_test', 'stripe_status' => 'active',
        'stripe_price' => $price, 'quantity' => 1, 'price_cents' => 2000,
    ]);
    $subscription->items()->create([
        'stripe_id' => 'si_test', 'stripe_product' => 'prod_test', 'stripe_price' => $price, 'quantity' => 1,
    ]);

    return $user;
}

function monthlyPlanOnPrice(string $price): SubscriptionType
{
    return SubscriptionType::withoutEvents(function () use ($price) {
        $type = SubscriptionType::create([
            'name' => 'Monthly', 'price_cents' => 2000, 'billing_interval' => 'month', 'active' => true,
        ]);
        $type->forceFill(['stripe_product_id' => 'prod_test', 'stripe_price_id' => $price])->save();

        return $type;
    });
}

afterEach(fn () => ApiRequestor::setHttpClient(CurlClient::instance()));

// --- Cancelling a subscription ---------------------------------------------

test('a customer cancels an active subscription and keeps access until the paid period ends', function () {
    $periodEnd = now()->addDays(12)->getTimestamp();
    $stripe = stripeApiAnswering('price_test', $periodEnd);
    $user = subscriberOnPrice('price_test');

    $this->actingAs($user)->delete('/subscriptions/subscription')
        ->assertRedirect(route('subscriptions.index'))
        ->assertSessionHas('status', 'subscription-cancelled');

    $subscription = $user->subscription('default')->fresh();

    expect($subscription->canceled())->toBeTrue()
        ->and($subscription->onGracePeriod())->toBeTrue()
        ->and($subscription->ends_at->getTimestamp())->toBe($periodEnd)
        ->and($stripe->writes()[0]['params'])->toMatchArray(['cancel_at_period_end' => 'true']);
});

test('a customer with no active subscription has nothing to cancel and Stripe is not called', function () {
    $stripe = stripeApiAnswering('price_test', now()->addDays(12)->getTimestamp());
    $user = User::factory()->create();

    $this->actingAs($user)->get('/subscriptions')
        ->assertInertia(fn ($page) => $page->where('activeSubscription', null));

    $this->actingAs($user)->delete('/subscriptions/subscription')
        ->assertSessionHas('status', 'subscription-cancelled');

    expect($stripe->requests)->toBeEmpty();
});

// --- Changing the subscription type ----------------------------------------

test('a customer switches to the plan\'s current price and the subscription is updated', function () {
    $stripe = stripeApiAnswering('price_new', now()->addDays(12)->getTimestamp());
    monthlyPlanOnPrice('price_new');
    $user = subscriberOnPrice('price_old');

    $this->actingAs($user)->post('/subscriptions/subscription/swap')
        ->assertRedirect(route('subscriptions.index'))
        ->assertSessionHas('status', 'price-updated');

    $subscription = $user->subscription('default')->fresh();

    expect($subscription->stripe_price)->toBe('price_new')
        ->and($subscription->canceled())->toBeFalse()
        ->and($stripe->writes())->toHaveCount(1);
});

test('choosing the price the subscription is already on changes nothing and says so', function () {
    $stripe = stripeApiAnswering('price_new', now()->addDays(12)->getTimestamp());
    monthlyPlanOnPrice('price_new');
    $user = subscriberOnPrice('price_new');

    $this->actingAs($user)->post('/subscriptions/subscription/swap')
        ->assertRedirect(route('subscriptions.index'))
        ->assertSessionHas('status', 'price-unchanged');

    expect($user->subscription('default')->fresh()->stripe_price)->toBe('price_new')
        ->and($stripe->writes())->toBeEmpty();
});
