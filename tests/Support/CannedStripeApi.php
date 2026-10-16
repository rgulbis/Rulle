<?php

namespace Tests\Support;

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * A canned Stripe API for the calls Cashier makes directly (cancelling a
 * subscription, reading prices, swapping the price). Every call is recorded,
 * and the answers come from memory instead of from Stripe.
 */
final class CannedStripeApi implements ClientInterface
{
    /** @var list<array{method: string, path: string, params: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param  array<string, int>  $priceAmounts  unit amount in cents by Stripe price id
     */
    public function __construct(
        private readonly string $subscriptionPrice,
        private readonly int $periodEnd,
        private readonly array $priceAmounts = [],
    ) {}

    public static function install(string $subscriptionPrice, int $periodEnd, array $priceAmounts = []): self
    {
        config(['cashier.secret' => 'sk_test_canned']);

        $api = new self($subscriptionPrice, $periodEnd, $priceAmounts);
        ApiRequestor::setHttpClient($api);

        return $api;
    }

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
            'id' => $id, 'object' => 'price', 'product' => 'prod_test',
            'unit_amount' => $this->priceAmounts[$id] ?? 2000,
            'recurring' => ['interval' => 'month', 'usage_type' => 'licensed'],
        ];
    }
}
