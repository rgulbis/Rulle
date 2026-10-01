<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\Reservation;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Symfony\Component\HttpFoundation\Response;

class StripeWebhookController extends CashierWebhookController
{
    /**
     * A one-time-pass or reservation checkout a customer starts but never
     * finishes (closes the tab, card declines with no retry, etc.) left its
     * row `pending` forever — nothing ever revisited it. Both controllers
     * already handle the case where the customer explicitly clicks back
     * from Stripe (their cancel_url routes straight to the normal cancel
     * action), so this is specifically the backstop for someone who just
     * closes the tab instead. Stripe expires an unfinished Checkout Session
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
                ->update(['status' => 'cancelled']);
        }

        return $this->successMethod();
    }
}
