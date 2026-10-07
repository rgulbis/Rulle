<?php

namespace App\Http\Controllers\Subscriptions;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\SubscriptionType;
use App\Models\User;
use App\Support\Payments\CheckoutFulfillment;
use App\Support\Payments\StripeGateway;
use Illuminate\Contracts\Cache\LockTimeoutException;
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
        private readonly StripeGateway $stripe,
        private readonly CheckoutFulfillment $fulfillment,
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

        // The webhook records this too; doing it here keeps the ledger right
        // even before Stripe's event arrives.
        $subscription->forceFill(['price_cents' => $type->price_cents])->save();

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
        abort_unless($subscriptionType->active && $subscriptionType->stripe_price_id, 404);

        $user = $request->user();

        // One checkout at a time per customer. A double click, a second tab
        // or a script firing the request twice would otherwise each pass the
        // "already subscribed?" check before either has a subscription, and
        // each open its own Stripe session — and two paid sessions mean two
        // subscriptions billed every month. The second request waits here
        // and then finds the first one's session to reuse.
        try {
            return Cache::lock("subscription-checkout:{$user->id}", 30)
                ->block(5, fn () => $this->startCheckout($user, $subscriptionType));
        } catch (LockTimeoutException) {
            abort(429, 'Another checkout is already in progress.');
        }
    }

    private function startCheckout(User $user, SubscriptionType $subscriptionType): SymfonyResponse
    {
        $successUrl = route('subscriptions.success').'?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = route('subscriptions.checkout-cancelled');

        if ($subscriptionType->isRecurring()) {
            $alreadySubscribed = $user->subscribed('default')
                && ! $user->subscription('default')->canceled();

            abort_if($alreadySubscribed, 409, 'You already have an active subscription.');

            // A session the customer opened earlier and never finished is
            // still good for 24 hours: send them back to it rather than
            // minting a second one. (Remembered per plan price, so a plan
            // that was repriced meanwhile gets a fresh session.)
            $cacheKey = "subscription-checkout-session:{$user->id}:{$subscriptionType->stripe_price_id}";

            if ($url = $this->openSessionUrl(Cache::get($cacheKey))) {
                return Inertia::location($url);
            }

            $session = $this->stripe->createSubscriptionCheckout($user, $subscriptionType->stripe_price_id, $successUrl, $cancelUrl);

            Cache::put($cacheKey, $session->id, now()->addHours(23));

            // Inertia can't follow a plain redirect to an external domain (it
            // would try to XHR-fetch Stripe's page); Inertia::location does
            // a full browser navigation instead.
            return Inertia::location($session->url);
        }

        $pending = Purchase::where('user_id', $user->id)
            ->where('subscription_type_id', $subscriptionType->id)
            ->where('status', 'pending')
            ->latest('id')
            ->value('stripe_checkout_session_id');

        if ($url = $this->openSessionUrl($pending)) {
            return Inertia::location($url);
        }

        $session = $this->stripe->createPassCheckout($user, $subscriptionType->stripe_price_id, $successUrl, $cancelUrl);

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
     * The URL of a Checkout Session that can still be paid, or null if it
     * is unknown, finished or expired (or Stripe can't be asked right now —
     * then a fresh session is the safe choice).
     */
    private function openSessionUrl(mixed $sessionId): ?string
    {
        if (! is_string($sessionId) || $sessionId === '') {
            return null;
        }

        try {
            $session = $this->stripe->retrieveCheckoutSession($sessionId);
        } catch (ApiErrorException) {
            return null;
        }

        return $session->status === 'open' && $session->url ? $session->url : null;
    }

    /**
     * Where Stripe sends the customer after paying. A one-time pass is
     * fulfilled by the webhook; this runs the same idempotent code so the
     * customer sees it at once. Only a session that is the customer's own
     * counts: a pass is looked up by its owner, a recurring subscription by
     * the Stripe customer the session belongs to.
     */
    public function success(Request $request): RedirectResponse
    {
        $sessionId = $request->query('session_id');
        $user = $request->user();
        $completed = false;

        if (is_string($sessionId) && $sessionId !== '') {
            $isOwnPurchase = Purchase::where('stripe_checkout_session_id', $sessionId)
                ->where('user_id', $user->id)
                ->exists();

            // Null for an id Stripe does not know, or when Stripe is
            // unreachable: nothing to show yet. The webhook fulfils a real
            // payment either way.
            $session = $this->stripe->findCheckoutSession($sessionId);

            if ($session !== null && $isOwnPurchase) {
                $this->fulfillment->fulfill($session);

                $completed = $session->status === 'complete';
            } elseif ($session !== null && $user->stripe_id && $session->customer === $user->stripe_id) {
                // Recurring subscription: Cashier mirrors it from Stripe's
                // subscription webhooks, there is no local pass to fulfil.
                $completed = $session->status === 'complete';
            }
        }

        return redirect()->route('subscriptions.index')
            ->with('status', $completed ? 'purchase-complete' : 'purchase-incomplete');
    }

    /**
     * Stripe's cancel_url. Strictly read-only: a pass the customer backed
     * out of simply stays pending until Stripe expires the session (the
     * `checkout.session.expired` webhook then abandons it). Nothing that
     * changes state belongs behind a GET.
     */
    public function checkoutCancelled(): RedirectResponse
    {
        return redirect()->route('subscriptions.index')->with('status', 'purchase-cancelled');
    }
}
