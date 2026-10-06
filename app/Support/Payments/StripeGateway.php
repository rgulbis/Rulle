<?php

namespace App\Support\Payments;

use App\Models\User;
use Laravel\Cashier\Cashier;
use Stripe\Checkout\Session;

/**
 * The only place payment code talks to Stripe, so tests can swap it for a
 * fake (`app()->instance(StripeGateway::class, ...)`) instead of making
 * network calls.
 */
class StripeGateway
{
    /**
     * A one-off payment Checkout Session for a reservation.
     *
     * @return array{id: string, url: string}
     */
    public function createReservationCheckout(
        User $user,
        int $amountCents,
        string $description,
        string $successUrl,
        string $cancelUrl,
        int $expiresAt,
    ): array {
        $session = $user->checkoutCharge($amountCents, $description, 1, [
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'mode' => 'payment',
            'expires_at' => $expiresAt,
        ])->asStripeCheckoutSession();

        return ['id' => $session->id, 'url' => (string) $session->url];
    }

    public function checkoutSession(string $sessionId): Session
    {
        return Cashier::stripe()->checkout->sessions->retrieve($sessionId);
    }

    /**
     * How much of a payment has already gone back to the customer, in cents.
     */
    public function refundedCents(string $paymentIntentId): int
    {
        $intent = Cashier::stripe()->paymentIntents->retrieve($paymentIntentId, ['expand' => ['latest_charge']]);
        $charge = $intent->latest_charge;

        return is_object($charge) ? (int) $charge->amount_refunded : 0;
    }

    /**
     * The idempotency key makes retrying a refund safe: Stripe returns the
     * original refund instead of creating a second one.
     */
    public function refund(string $paymentIntentId, string $idempotencyKey, ?int $amountCents = null): void
    {
        $params = ['payment_intent' => $paymentIntentId];

        if ($amountCents !== null) {
            $params['amount'] = $amountCents;
        }

        Cashier::stripe()->refunds->create($params, ['idempotency_key' => $idempotencyKey]);
    }

    /**
     * Ends a subscription at Stripe immediately (no further invoices).
     */
    public function cancelSubscriptionNow(string $stripeSubscriptionId): void
    {
        Cashier::stripe()->subscriptions->cancel($stripeSubscriptionId);
    }
}
