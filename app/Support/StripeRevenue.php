<?php

namespace App\Support;

use App\Models\User;
use App\Support\Payments\StripeGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * Recurring-subscription revenue lives entirely on Stripe's side - Cashier
 * only mirrors subscription *status*, not invoice amounts, into a local
 * table - so this is the only way to report it at all.
 *
 * Only invoices of this app's own Stripe customers are counted (the account
 * can hold others, e.g. leftovers from testing or another project), and
 * credit notes issued after payment are deducted. A refund made directly on
 * the charge, outside a credit note, is not visible on the invoice and is not
 * deducted.
 */
class StripeRevenue
{
    /** Short, so a refund or a new subscriber shows up soon, but still shields Stripe's API. */
    private const CACHE_MINUTES = 2;

    /**
     * Net paid invoice amounts (cents) of this app's customers, optionally
     * restricted to a date range. Cached briefly: this calls Stripe's API
     * (with pagination for accounts with more than 100 paid invoices), and
     * production runs a single `php artisan serve` process with no queue
     * workers - an uncached version here would mean every admin dashboard
     * load makes a handful of synchronous, network-bound Stripe calls.
     */
    public static function paidSubscriptionRevenueCents(?int $sinceTimestamp = null, ?int $untilTimestamp = null): int
    {
        $cacheKey = 'stripe-subscription-revenue:v2:'.($sinceTimestamp ?? 'all').':'.($untilTimestamp ?? 'all');

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_MINUTES), function () use ($sinceTimestamp, $untilTimestamp) {
            // A dashboard stat failing shouldn't take the rest of the admin
            // panel down with it - Stripe being briefly unreachable (or, in
            // CI/local setups with no key configured at all) degrades to
            // "0 €" here rather than a 500.
            try {
                // Closed accounts keep their Stripe customer (its payments
                // are records), so their past invoices still count.
                $ours = User::withTrashed()->whereNotNull('stripe_id')->pluck('stripe_id')->flip();

                $total = 0;

                foreach (app(StripeGateway::class)->paidInvoices($sinceTimestamp, $untilTimestamp) as $invoice) {
                    if (! $ours->has($invoice->customer)) {
                        continue;
                    }

                    $total += max(0, (int) $invoice->amount_paid - (int) ($invoice->post_payment_credit_notes_amount ?? 0));
                }

                return $total;
            } catch (ApiErrorException $e) {
                Log::warning('Could not fetch Stripe subscription revenue.', ['exception' => $e]);

                return 0;
            }
        });
    }
}
