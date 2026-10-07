<?php

namespace App\Support\Payments;

use App\Models\Reservation;
use App\Models\User;
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
     * A one-off charge for a reservation, priced at what it was booked for.
     */
    public function createReservationCheckout(User $user, Reservation $reservation, string $successUrl, string $cancelUrl): Session
    {
        $durationMinutes = $reservation->starts_at->diffInMinutes($reservation->ends_at);

        return $user->checkoutCharge(
            $reservation->price_cents,
            'Park reservation ('.$durationMinutes.' min, '.$reservation->group_size.' people)',
            1,
            ['success_url' => $successUrl, 'cancel_url' => $cancelUrl, 'mode' => 'payment'],
        )->asStripeCheckoutSession();
    }

    public function createSubscriptionCheckout(User $user, string $priceId, string $successUrl, string $cancelUrl): Session
    {
        return $user->newSubscription('default', $priceId)
            ->checkout(['success_url' => $successUrl, 'cancel_url' => $cancelUrl])
            ->asStripeCheckoutSession();
    }

    public function createPassCheckout(User $user, string $priceId, string $successUrl, string $cancelUrl): Session
    {
        return $user->checkout([$priceId => 1], [
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'mode' => 'payment',
        ])->asStripeCheckoutSession();
    }

    /**
     * Ends a subscription at Stripe immediately (no further billing).
     */
    public function cancelSubscriptionNow(string $stripeSubscriptionId): void
    {
        Cashier::stripe()->subscriptions->cancel($stripeSubscriptionId);
    }

    /**
     * Replaces what Stripe holds about a closed account's customer with the
     * same placeholders the local row got. The customer itself stays: its
     * payments are records that have to be kept.
     */
    public function anonymiseCustomer(string $customerId, string $name, string $email): void
    {
        Cashier::stripe()->customers->update($customerId, [
            'name' => $name,
            'email' => $email,
            'phone' => '',
            'description' => '',
        ]);
    }

    /**
     * The idempotency keys on the three calls below make a retried sync
     * (the process died after Stripe answered, before the id was saved)
     * return the object that was already created instead of a second one.
     */
    public function createProduct(string $name, string $idempotencyKey): string
    {
        return Cashier::stripe()->products->create(['name' => $name], ['idempotency_key' => $idempotencyKey])->id;
    }

    public function renameProduct(string $productId, string $name): void
    {
        Cashier::stripe()->products->update($productId, ['name' => $name]);
    }

    /**
     * @param  string|null  $interval  'month' or 'year' for a recurring price, null for a one-time one
     */
    public function createPrice(string $productId, int $amountCents, ?string $interval, string $idempotencyKey): string
    {
        return Cashier::stripe()->prices->create([
            'product' => $productId,
            'unit_amount' => $amountCents,
            'currency' => 'eur',
            ...$interval !== null ? ['recurring' => ['interval' => $interval]] : [],
        ], ['idempotency_key' => $idempotencyKey])->id;
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
