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
            ] : null,
            'activePurchase' => $user->activeOneTimePurchase(),
            'status' => $request->session()->get('status'),
        ]);
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
