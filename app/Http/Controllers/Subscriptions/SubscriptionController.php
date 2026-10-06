<?php

namespace App\Http\Controllers\Subscriptions;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\SubscriptionType;
use App\Models\User;
use App\Support\Payments\CheckoutFulfillment;
use App\Support\Payments\StripeGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class SubscriptionController extends Controller
{
    public function __construct(
        private CheckoutFulfillment $fulfillment,
        private StripeGateway $stripe,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $subscription = $user->subscription('default');

        return Inertia::render('subscriptions/index', [
            'plans' => SubscriptionType::where('active', true)->get(),
            'mostPopularPlanId' => SubscriptionType::mostPopularId(),
            'activeSubscription' => $subscription ? [
                'stripe_status' => $subscription->stripe_status,
                'ends_at' => $subscription->ends_at,
                'on_grace_period' => $subscription->onGracePeriod(),
                'canceled' => $subscription->canceled(),
            ] : null,
            'activePurchase' => $user->activeOneTimePurchase(),
            'priceChange' => ($subscription && ! $subscription->canceled())
                ? $this->detectPriceChange($subscription)
                : null,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Stripe prices are immutable, so editing a plan's price in the admin
     * panel creates a new Price rather than changing the one a subscriber
     * is already on. This detects whether the plan they're subscribed to
     * (matched by Stripe Product, since the Price id itself changes) now
     * has a different current price than what they're actually paying.
     */
    /**
     * @return array{current_price_cents: int, new_price_cents: int}|null
     */
    protected function detectPriceChange(Subscription $subscription): ?array
    {
        $currentPriceId = $subscription->stripe_price;

        if (! $currentPriceId) {
            return null;
        }

        // This calls Stripe's API (twice, worst case) on every single load
        // of /subscriptions for anyone with an active plan — cached briefly
        // so reloading or repeatedly clicking back into this page doesn't
        // re-trigger those network calls every time. Production runs a
        // single `php artisan serve` process with no queue workers, so a
        // burst of these synchronous, network-bound requests was enough to
        // stall the whole site for every visitor, not just the one doing it.
        // Wrapped in an array (not returned bare) because Cache::remember()
        // can't distinguish "cached null" from "not cached yet" otherwise,
        // and a plan with no price change at all — the common case — would
        // never actually get cached.
        $cached = Cache::remember(
            "subscription-price-change:{$currentPriceId}",
            now()->addMinutes(5),
            function () use ($currentPriceId) {
                $currentPrice = Cashier::stripe()->prices->retrieve($currentPriceId);
                $type = SubscriptionType::where('stripe_product_id', $currentPrice->product)->first();

                if (! $type || ! $type->stripe_price_id || $type->stripe_price_id === $currentPriceId) {
                    return ['change' => null];
                }

                $newPrice = Cashier::stripe()->prices->retrieve($type->stripe_price_id);

                return ['change' => [
                    'current_price_cents' => $currentPrice->unit_amount,
                    'new_price_cents' => $newPrice->unit_amount,
                ]];
            },
        );

        return $cached['change'];
    }

    public function swapToCurrentPrice(Request $request): RedirectResponse
    {
        $subscription = $request->user()->subscription('default');

        abort_unless($subscription && ! $subscription->canceled(), 404);

        $currentPriceId = $subscription->stripe_price;
        $currentPrice = $currentPriceId ? Cashier::stripe()->prices->retrieve($currentPriceId) : null;
        $type = $currentPrice ? SubscriptionType::where('stripe_product_id', $currentPrice->product)->first() : null;

        abort_unless($type && $type->stripe_price_id, 404);

        $subscription->swap($type->stripe_price_id);

        return redirect()->route('subscriptions.index')->with('status', 'price-updated');
    }

    public function cancelSubscription(Request $request): RedirectResponse
    {
        $subscription = $request->user()->subscription('default');

        if ($subscription && ! $subscription->canceled()) {
            $subscription->cancel();
        }

        return redirect()->route('subscriptions.index')->with('status', 'subscription-cancelled');
    }

    public function checkout(
        Request $request,
        SubscriptionType $subscriptionType,
    ): SymfonyResponse {
        abort_unless($subscriptionType->active && $subscriptionType->stripe_price_id && $subscriptionType->isSellable(), 404);

        $user = $request->user();

        $successUrl = route('subscriptions.success').'?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = route('subscriptions.cancel');

        if ($subscriptionType->isRecurring()) {
            return $this->recurringCheckout($user, $subscriptionType, $successUrl, $cancelUrl);
        }

        $session = $user->checkout([$subscriptionType->stripe_price_id => 1], [
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'mode' => 'payment',
            'expires_at' => now()->addMinutes(30)->timestamp,
        ])->asStripeCheckoutSession();

        Purchase::create([
            'user_id' => $user->id,
            'subscription_type_id' => $subscriptionType->id,
            'stripe_checkout_session_id' => $session->id,
            'price_cents' => $subscriptionType->price_cents,
            'status' => 'pending',
        ]);

        return Inertia::location($session->url);
    }

    /**
     * Two simultaneous clicks must not produce two Stripe Checkout sessions
     * (and so potentially two paid subscriptions): a per-user lock covers the
     * check-then-create window, and while a session from an earlier click is
     * still open the customer is sent back to that one instead.
     */
    private function recurringCheckout(
        User $user,
        SubscriptionType $subscriptionType,
        string $successUrl,
        string $cancelUrl,
    ): SymfonyResponse {
        $lock = Cache::lock("subscription-checkout:{$user->id}", 30);

        abort_unless($lock->get(), 429, 'A checkout is already being started.');

        try {
            $alreadySubscribed = $user->subscribed('default')
                && ! $user->subscription('default')->canceled();

            abort_if($alreadySubscribed, 409, 'You already have an active subscription.');

            $openKey = "subscription-checkout-open:{$user->id}:{$subscriptionType->id}";
            $openSessionId = Cache::get($openKey);

            if (is_string($openSessionId)) {
                $open = $this->stripe->checkoutSession($openSessionId);

                if ($open->status === 'open' && $open->url) {
                    return Inertia::location($open->url);
                }
            }

            $session = $user->newSubscription('default', $subscriptionType->stripe_price_id)
                ->checkout([
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                    'expires_at' => now()->addMinutes(30)->timestamp,
                ])
                ->asStripeCheckoutSession();

            Cache::put($openKey, $session->id, now()->addMinutes(30));

            // Inertia can't follow a plain redirect to an external domain (it
            // would try to XHR-fetch Stripe's page); Inertia::location does
            // a full browser navigation instead.
            return Inertia::location($session->url);
        } finally {
            $lock->release();
        }
    }

    /**
     * Where Stripe sends the browser after paying. The
     * `checkout.session.completed` webhook is what actually activates a
     * pass; this just shows the result (fulfilling it first if the webhook is
     * late). Only the pass's own buyer can look up a session here.
     */
    public function success(Request $request): RedirectResponse
    {
        $user = $request->user();
        $sessionId = $request->query('session_id');
        $completed = false;

        if (is_string($sessionId)) {
            try {
                $session = $this->stripe->checkoutSession($sessionId);
            } catch (ApiErrorException) {
                $session = null;
            }

            // Ownership: a one-time pass checkout is tied to its buyer by the
            // local Purchase row; a recurring one (no row — Cashier syncs it
            // by webhook) by the Stripe customer on the session.
            $purchase = Purchase::where('stripe_checkout_session_id', $sessionId)
                ->where('user_id', $user->id)
                ->first();
            $owned = $purchase !== null
                || ($session && $session->customer && $session->customer === $user->stripe_id);

            if ($session && $owned) {
                $completed = $session->status === 'complete';

                if ($purchase && $completed && $session->payment_status === 'paid') {
                    $this->fulfillment->fulfill($sessionId, is_string($session->payment_intent) ? $session->payment_intent : null);
                }
            }
        }

        return redirect()->route('subscriptions.index')
            ->with('status', $completed ? 'purchase-complete' : 'purchase-incomplete');
    }

    /**
     * Stripe sends the browser here by GET if the customer backs out of
     * Checkout. Read-only on purpose: an abandoned pass checkout is cleaned
     * up by the `checkout.session.expired` webhook, not by a link anyone could
     * be tricked into opening.
     */
    public function cancel(): RedirectResponse
    {
        return redirect()->route('subscriptions.index')->with('status', 'purchase-cancelled');
    }
}
