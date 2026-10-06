<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\Reservation;
use App\Support\Payments\CheckoutFulfillment;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Laravel\Cashier\Subscription;
use Symfony\Component\HttpFoundation\Response;

class StripeWebhookController extends CashierWebhookController
{
    /**
     * Cashier mirrors a subscription's status but not what it costs, so the
     * amount is recorded from Stripe's own payload — the payments ledger then
     * shows what the customer actually agreed to pay, not whatever the plan
     * costs today.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        $response = parent::handleCustomerSubscriptionCreated($payload);
        $this->recordSubscriptionPrice($payload);
        $this->flagDuplicateSubscription($payload);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        $response = parent::handleCustomerSubscriptionUpdated($payload);
        $this->recordSubscriptionPrice($payload);

        return $response;
    }

    /**
     * Checkout is guarded against a customer starting two subscriptions, but
     * if one ever slips through (two tabs, a stale session) the customer is
     * being billed twice. Nothing is cancelled automatically — that would
     * mean deciding which one to refund — but it must not go unnoticed.
     *
     * @param  array<string, mixed>  $payload
     */
    private function flagDuplicateSubscription(array $payload): void
    {
        $stripeId = $payload['data']['object']['id'] ?? null;
        $subscription = $stripeId ? Subscription::where('stripe_id', $stripeId)->first() : null;

        if (! $subscription) {
            return;
        }

        $live = Subscription::where('user_id', $subscription->user_id)
            ->where('type', $subscription->type)
            ->whereNotIn('stripe_status', ['canceled', 'incomplete_expired'])
            ->count();

        if ($live > 1) {
            Log::critical('Customer has more than one live subscription — they are being billed twice.', [
                'user_id' => $subscription->user_id,
                'subscription' => $stripeId,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordSubscriptionPrice(array $payload): void
    {
        $object = $payload['data']['object'] ?? [];
        $amount = $object['items']['data'][0]['price']['unit_amount'] ?? null;

        if (isset($object['id']) && is_int($amount)) {
            Subscription::where('stripe_id', $object['id'])->update(['price_cents' => $amount]);
        }
    }

    /**
     * The source of truth for "this checkout was paid". Fulfilment must not
     * hinge on the customer's browser making it back to the success URL —
     * they may close the tab right after paying — so this activates the pass
     * or reservation on its own. Idempotent with the success-URL path.
     * Requires `checkout.session.completed` (and, for delayed payment
     * methods, `checkout.session.async_payment_succeeded`) to be enabled on
     * the Stripe webhook endpoint.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCheckoutSessionCompleted(array $payload): Response
    {
        $session = $payload['data']['object'] ?? [];

        if (($session['payment_status'] ?? null) === 'paid') {
            $this->fulfill($session);
        }

        return $this->successMethod();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCheckoutSessionAsyncPaymentSucceeded(array $payload): Response
    {
        $this->fulfill($payload['data']['object'] ?? []);

        return $this->successMethod();
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function fulfill(array $session): void
    {
        if (isset($session['id']) && is_string($session['id'])) {
            app(CheckoutFulfillment::class)->fulfill(
                $session['id'],
                is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null,
            );
        }
    }

    /**
     * A one-time-pass or reservation checkout a customer starts but never
     * finishes (closes the tab, card declines with no retry, etc.) would
     * otherwise stay `pending` forever. Backing out of Stripe's page does
     * nothing by itself (its cancel_url is a read-only GET), so this is how
     * every abandoned checkout gets cleaned up. Stripe expires an unfinished Checkout Session
     * on its own (by default, 24 hours after it was created) and fires this
     * event when it does — the only reliable way to know a checkout is
     * truly dead rather than just slow. Requires `checkout.session.expired`
     * to be enabled on the Stripe webhook endpoint's subscribed events.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCheckoutSessionExpired(array $payload): Response
    {
        $sessionId = $payload['data']['object']['id'] ?? null;

        if ($sessionId) {
            Purchase::where('stripe_checkout_session_id', $sessionId)
                ->where('status', 'pending')
                ->update(['status' => 'abandoned']);

            Reservation::where('stripe_checkout_session_id', $sessionId)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        }

        return $this->successMethod();
    }
}
