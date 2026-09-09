<?php

namespace App\Http\Controllers\Subscriptions;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\SubscriptionType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;

class SubscriptionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $subscription = $user->subscription('default');

        return Inertia::render('subscriptions/index', [
            'plans' => SubscriptionType::where('active', true)->get(),
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

        $currentPrice = Cashier::stripe()->prices->retrieve($currentPriceId);
        $type = SubscriptionType::where('stripe_product_id', $currentPrice->product)->first();

        if (! $type || ! $type->stripe_price_id || $type->stripe_price_id === $currentPriceId) {
            return null;
        }

        $newPrice = Cashier::stripe()->prices->retrieve($type->stripe_price_id);

        return [
            'current_price_cents' => $currentPrice->unit_amount,
            'new_price_cents' => $newPrice->unit_amount,
        ];
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

    public function checkout(Request $request, SubscriptionType $subscriptionType): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($subscriptionType->active && $subscriptionType->stripe_price_id, 404);

        $user = $request->user();

        $successUrl = route('subscriptions.success').'?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = route('subscriptions.cancel');

        if ($subscriptionType->isRecurring()) {
            $session = $user->newSubscription('default', $subscriptionType->stripe_price_id)
                ->checkout(['success_url' => $successUrl, 'cancel_url' => $cancelUrl])
                ->asStripeCheckoutSession();

            // Inertia can't follow a plain redirect to an external domain (it
            // would try to XHR-fetch Stripe's page); Inertia::location does
            // a full browser navigation instead.
            return Inertia::location($session->url);
        }

        $session = $user->checkout([$subscriptionType->stripe_price_id => 1], [
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'mode' => 'payment',
        ])->asStripeCheckoutSession();

        Purchase::create([
            'user_id' => $user->id,
            'subscription_type_id' => $subscriptionType->id,
            'stripe_checkout_session_id' => $session->id,
            'status' => 'pending',
        ]);

        return Inertia::location($session->url);
    }

    public function success(Request $request): RedirectResponse
    {
        $sessionId = $request->query('session_id');
        $completed = false;

        if ($sessionId) {
            $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId);
            $completed = $session->status === 'complete';

            if ($completed && $session->payment_status === 'paid') {
                $purchase = Purchase::where('stripe_checkout_session_id', $sessionId)->first();

                if ($purchase && $purchase->status === 'pending') {
                    $purchase->update([
                        'status' => 'active',
                        'visits_remaining' => $purchase->subscriptionType->visit_limit,
                        'valid_date' => now()->toDateString(),
                    ]);
                }
            }
        }

        return redirect()->route('subscriptions.index')
            ->with('status', $completed ? 'purchase-complete' : 'purchase-incomplete');
    }

    public function cancel(): RedirectResponse
    {
        return redirect()->route('subscriptions.index')->with('status', 'purchase-cancelled');
    }
}
