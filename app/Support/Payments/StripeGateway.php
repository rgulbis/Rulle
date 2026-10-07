<?php

namespace App\Support\Payments;

use Laravel\Cashier\Cashier;
use Stripe\Checkout\Session;
use Stripe\Refund;

/**
 * Every Stripe call the payment flows make goes through here, so tests can
 * swap in a fake instead of talking to the real API.
 */
class StripeGateway
{
    public function retrieveCheckoutSession(string $sessionId): Session
    {
        return Cashier::stripe()->checkout->sessions->retrieve($sessionId);
    }

    /**
     * The idempotency key makes a retried request (timeout, double click,
     * the retry command racing a request) return the first refund instead of
     * creating a second one.
     */
    public function createRefund(string $paymentIntentId, int $amountCents, string $idempotencyKey): Refund
    {
        return Cashier::stripe()->refunds->create(
            ['payment_intent' => $paymentIntentId, 'amount' => $amountCents],
            ['idempotency_key' => $idempotencyKey],
        );
    }
}
