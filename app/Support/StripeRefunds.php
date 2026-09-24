<?php

namespace App\Support;

use Laravel\Cashier\Cashier;

class StripeRefunds
{
    /**
     * Refunds the payment behind a completed Stripe Checkout session.
     * Returns whether a refund was actually issued — false if there's no
     * session on record, or the session has no payment to refund (e.g. it
     * was never completed).
     */
    public static function refundCheckoutSession(?string $sessionId): bool
    {
        if (! $sessionId) {
            return false;
        }

        $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId);

        if (! $session->payment_intent) {
            return false;
        }

        Cashier::stripe()->refunds->create(['payment_intent' => $session->payment_intent]);

        return true;
    }
}
