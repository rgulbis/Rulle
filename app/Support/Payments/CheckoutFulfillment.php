<?php

namespace App\Support\Payments;

use App\Models\Purchase;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * Turns a *paid* Stripe Checkout Session into a usable pass or an active
 * reservation. Stripe's `checkout.session.completed` webhook is the source of
 * truth for that; the browser landing on the success URL calls the same code
 * only so the customer sees the result immediately. Both paths are
 * idempotent, so whichever runs second is a no-op.
 */
class CheckoutFulfillment
{
    public function __construct(private Refunds $refunds) {}

    /**
     * Call only once Stripe has confirmed the session is paid. Sessions that
     * don't belong to a local pass/reservation (e.g. subscriptions, which
     * Cashier syncs separately) are ignored.
     */
    public function fulfill(string $sessionId, ?string $paymentIntentId): void
    {
        /** @var Reservation|Purchase|null $refundDue */
        $refundDue = DB::transaction(function () use ($sessionId, $paymentIntentId) {
            $purchase = Purchase::where('stripe_checkout_session_id', $sessionId)->lockForUpdate()->first();

            if ($purchase) {
                $this->fulfillPurchase($purchase, $paymentIntentId);

                return null;
            }

            $reservation = Reservation::where('stripe_checkout_session_id', $sessionId)->lockForUpdate()->first();

            return $reservation ? $this->fulfillReservation($reservation, $paymentIntentId) : null;
        });

        // After the transaction: no network call while holding the write lock.
        if ($refundDue) {
            $this->refunds->issue($refundDue);
        }
    }

    private function fulfillPurchase(Purchase $purchase, ?string $paymentIntentId): void
    {
        if ($purchase->payment_status !== 'unpaid') {
            return;
        }

        // `abandoned` is included on purpose: money was taken, so the pass
        // must work even if something already wrote the checkout off.
        $purchase->forceFill([
            'status' => 'active',
            'visits_remaining' => $purchase->subscriptionType->visit_limit,
            'valid_date' => now()->toDateString(),
            'payment_status' => 'paid',
            'stripe_payment_intent_id' => $paymentIntentId,
            'paid_at' => now(),
        ])->save();
    }

    /**
     * Returns the reservation if its payment now has to be refunded.
     */
    private function fulfillReservation(Reservation $reservation, ?string $paymentIntentId): ?Reservation
    {
        if ($reservation->payment_status !== 'unpaid') {
            return null;
        }

        $reservation->forceFill([
            'payment_status' => 'paid',
            'stripe_payment_intent_id' => $paymentIntentId,
            'paid_at' => now(),
        ]);

        // Paid, but the customer cancelled the pending reservation (or it
        // expired) while Stripe Checkout was still open in another tab, or
        // someone else's payment for an overlapping slot landed first.
        // Either way the money was taken for something we can't deliver.
        if ($reservation->status !== 'pending' || $reservation->overlappedByAnotherActiveReservation()) {
            $reservation->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => $reservation->cancelled_at ?? now(),
                'payment_status' => 'refund_pending',
            ])->save();

            return $reservation;
        }

        $reservation->forceFill(['status' => 'active'])->save();

        return null;
    }
}
