<?php

namespace App\Support\Payments;

use App\Models\Purchase;
use App\Models\Reservation;
use Stripe\Checkout\Session;

/**
 * Turns a paid Stripe Checkout session into a live pass or reservation.
 *
 * Stripe's webhook is the source of truth — the customer's browser may never
 * come back to the success URL — and the success URL runs the very same code
 * so the customer sees the result immediately. Both can fire, in either
 * order, even at the same moment, so every state change is one conditional
 * UPDATE (`WHERE status = 'pending'`): whoever wins does the work, the other
 * sees zero affected rows and reports the current state. That makes calling
 * this any number of times for one session safe.
 */
class CheckoutFulfillment
{
    public function __construct(private readonly Refunds $refunds) {}

    public function fulfill(Session $session): FulfillmentOutcome
    {
        if ($session->payment_status !== 'paid') {
            return FulfillmentOutcome::NotFulfilled;
        }

        if ($purchase = Purchase::where('stripe_checkout_session_id', $session->id)->first()) {
            return $this->fulfillPurchase($purchase);
        }

        if ($reservation = Reservation::where('stripe_checkout_session_id', $session->id)->first()) {
            return $this->fulfillReservation($reservation);
        }

        return FulfillmentOutcome::NotFulfilled;
    }

    private function fulfillPurchase(Purchase $purchase): FulfillmentOutcome
    {
        // `abandoned` too: a paid pass has to be honoured even if the row was
        // written off before the money arrived.
        Purchase::whereKey($purchase->id)
            ->whereIn('status', ['pending', 'abandoned'])
            ->update([
                'status' => 'active',
                'payment_status' => Purchase::PAYMENT_PAID,
                'visits_remaining' => $purchase->subscriptionType->visit_limit,
                'valid_date' => now()->toDateString(),
            ]);

        return in_array($purchase->fresh()?->status, ['active', 'used_up'], true)
            ? FulfillmentOutcome::Fulfilled
            : FulfillmentOutcome::NotFulfilled;
    }

    private function fulfillReservation(Reservation $reservation): FulfillmentOutcome
    {
        // Two people can both hold a pending reservation for overlapping
        // times; whoever's payment lands first gets the slot.
        if ($reservation->status === 'pending' && $reservation->overlappedByAnotherActiveReservation()) {
            $won = Reservation::whereKey($reservation->id)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled', 'payment_status' => Reservation::PAYMENT_PAID]);

            if ($won > 0) {
                $this->refunds->refundInFull($reservation->refresh());
            }

            return FulfillmentOutcome::SlotTaken;
        }

        $won = Reservation::whereKey($reservation->id)
            ->where('status', 'pending')
            ->update(['status' => 'active', 'payment_status' => Reservation::PAYMENT_PAID]);

        if ($won > 0) {
            return FulfillmentOutcome::Fulfilled;
        }

        // Cancelled while the checkout was still open (the customer cancelled
        // from their list, then paid in another tab). The slot may be gone by
        // now, so the booking is not revived — the money goes back instead.
        $lateWin = Reservation::whereKey($reservation->id)
            ->where('status', 'cancelled')
            ->where('payment_status', Reservation::PAYMENT_UNPAID)
            ->update(['payment_status' => Reservation::PAYMENT_PAID]);

        if ($lateWin > 0) {
            $this->refunds->refundInFull($reservation->refresh());

            return FulfillmentOutcome::RefundedAfterCancel;
        }

        $reservation->refresh();

        return match (true) {
            $reservation->status === 'active' => FulfillmentOutcome::Fulfilled,
            $reservation->status === 'cancelled' && $reservation->hasBeenPaid() => FulfillmentOutcome::RefundedAfterCancel,
            default => FulfillmentOutcome::NotFulfilled,
        };
    }
}
