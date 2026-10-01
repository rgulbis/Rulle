<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;

/**
 * Recurring-subscription revenue lives entirely on Stripe's side — Cashier
 * only mirrors subscription *status*, not invoice amounts, into a local
 * table — so this is the only way to report it at all.
 */
class StripeRevenue
{
    /**
     * Sum of paid invoice amounts (cents), optionally restricted to a
     * date range. Cached briefly: this calls Stripe's API (with pagination
     * for accounts with more than 100 paid invoices), and production runs a
     * single `php artisan serve` process with no queue workers — an
     * uncached version here would mean every admin dashboard load makes a
     * handful of synchronous, network-bound Stripe calls.
     */
    public static function paidSubscriptionRevenueCents(?int $sinceTimestamp = null, ?int $untilTimestamp = null): int
    {
        $cacheKey = 'stripe-subscription-revenue:'.($sinceTimestamp ?? 'all').':'.($untilTimestamp ?? 'all');

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($sinceTimestamp, $untilTimestamp) {
            $params = ['status' => 'paid', 'limit' => 100];

            if ($sinceTimestamp !== null || $untilTimestamp !== null) {
                $params['created'] = array_filter([
                    'gte' => $sinceTimestamp,
                    'lte' => $untilTimestamp,
                ], fn ($value) => $value !== null);
            }

            // A dashboard stat failing shouldn't take the rest of the admin
            // panel down with it — Stripe being briefly unreachable (or, in
            // CI/local setups with no key configured at all) degrades to
            // "0 €" here rather than a 500.
            try {
                $total = 0;

                foreach (Cashier::stripe()->invoices->all($params)->autoPagingIterator() as $invoice) {
                    $total += $invoice->amount_paid;
                }

                return $total;
            } catch (ApiErrorException $e) {
                Log::warning('Could not fetch Stripe subscription revenue.', ['exception' => $e]);

                return 0;
            }
        });
    }
}
