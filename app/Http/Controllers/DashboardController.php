<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\SubscriptionType;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('dashboard', [
            'status' => $request->session()->get('status'),
            'pass' => $this->currentPass($user),
            'nextReservation' => $this->nextReservation($user),
        ]);
    }

    /**
     * What the customer currently has access through — a recurring
     * subscription takes precedence over a one-time purchase, matching the
     * order the scanner checks them in.
     *
     * @return array<string, mixed>|null
     */
    protected function currentPass(User $user): ?array
    {
        if ($user->subscribed('default')) {
            $subscription = $user->subscription('default');
            // Matched on the price they're actually paying; after a price
            // change that's an older price, so the plan name can be unknown.
            $type = SubscriptionType::where('stripe_price_id', $subscription->stripe_price)->first();

            return [
                'kind' => 'subscription',
                'name' => $type?->name,
                'name_lv' => $type?->name_lv,
                'billing_interval' => $type?->billing_interval,
                'canceled' => $subscription->canceled(),
                'ends_at' => $subscription->ends_at,
                'renews_at' => $subscription->canceled() ? null : $this->nextRenewalDate($subscription),
            ];
        }

        $purchase = $user->activeOneTimePurchase();

        if (! $purchase) {
            return null;
        }

        return [
            'kind' => 'purchase',
            'name' => $purchase->subscriptionType->name,
            'name_lv' => $purchase->subscriptionType->name_lv,
            'unlimited_entries' => $purchase->subscriptionType->unlimited_entries,
            'visits_remaining' => $purchase->visits_remaining,
        ];
    }

    /**
     * When this subscription will next renew (and be charged) — Cashier
     * doesn't mirror this locally, so it's a live Stripe lookup, cached: the
     * dashboard loads on every visit, and production runs a single
     * `php artisan serve` process with no queue workers, the same reasoning
     * SubscriptionController::detectPriceChange() is cached for. Wrapped in
     * an array for the same reason that one is too — Cache::remember()
     * treats a cached `null` as "not cached" and would hit Stripe again on
     * every call otherwise.
     *
     * Cached as a plain Unix timestamp, not a Carbon instance: the cache is
     * configured with `serializable_classes => false`, so an object comes
     * back out as __PHP_Incomplete_Class and every cache hit would 500.
     *
     * A failed lookup degrades to no date shown (the card falls back to a
     * plain "renews automatically") rather than a 500 for the customer's
     * whole dashboard over what's ultimately a nice-to-have detail.
     */
    protected function nextRenewalDate(Subscription $subscription): ?CarbonInterface
    {
        try {
            $cached = Cache::remember(
                "subscription-renews-at:{$subscription->stripe_id}",
                now()->addHour(),
                fn () => ['timestamp' => $subscription->currentPeriodEnd()?->getTimestamp()],
            );

            return $cached['timestamp'] !== null ? Carbon::createFromTimestamp($cached['timestamp']) : null;
        } catch (ApiErrorException $e) {
            Log::warning('Could not fetch the next renewal date for a subscription.', ['exception' => $e]);

            return null;
        }
    }

    /**
     * The soonest paid reservation the user is part of, as owner or accepted
     * participant — only its time and size, same shape the rest of the app
     * shows a participant.
     *
     * @return array<string, mixed>|null
     */
    protected function nextReservation(User $user): ?array
    {
        $reservation = Reservation::involving($user)
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->first(['id', 'starts_at', 'ends_at', 'group_size']);

        return $reservation?->only(['id', 'starts_at', 'ends_at', 'group_size']);
    }
}
