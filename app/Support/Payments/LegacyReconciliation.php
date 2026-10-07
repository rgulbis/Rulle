<?php

namespace App\Support\Payments;

use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\ReservationSetting;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Settles rows from before payments were tracked properly, by asking Stripe
 * what really happened to each one's Checkout session:
 *
 *  - a pass or reservation still `pending`/`abandoned` whose session was in
 *    fact paid (the customer paid and never came back) is fulfilled;
 *  - a `cancelled` reservation recorded as `unpaid` (the old schema couldn't
 *    say refunded from forfeited) gets the payment state Stripe reports.
 *
 * It only ever reads from Stripe unless told to apply, and it is safe to run
 * repeatedly: a settled row no longer matches what it looks for.
 */
class LegacyReconciliation
{
    /** Rows touched in this window may still have a checkout in flight. */
    private const QUIET_MINUTES = 30;

    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly CheckoutFulfillment $fulfillment,
        private readonly Refunds $refunds,
    ) {}

    /**
     * @param  bool  $apply  Write the changes; without it nothing is modified.
     * @param  bool  $refundOwed  Also refund cancelled reservations that were cancelled early enough to be owed their money back and never got it.
     * @return Collection<int, ReconcileFinding>
     */
    public function run(bool $apply, bool $refundOwed = false): Collection
    {
        $findings = collect();
        $quiet = now()->subMinutes(self::QUIET_MINUTES);

        Purchase::query()
            ->whereIn('status', ['pending', 'abandoned'])
            ->where('payment_status', Purchase::PAYMENT_UNPAID)
            ->whereNotNull('stripe_checkout_session_id')
            ->where('updated_at', '<=', $quiet)
            ->each(fn (Purchase $purchase) => $findings->push($this->guarded(
                'Pass', $purchase, fn () => $this->unfinished($purchase, $apply),
            )));

        Reservation::query()
            ->where('status', 'pending')
            ->where('payment_status', Reservation::PAYMENT_UNPAID)
            ->whereNotNull('stripe_checkout_session_id')
            ->where('updated_at', '<=', $quiet)
            ->each(fn (Reservation $reservation) => $findings->push($this->guarded(
                'Reservation', $reservation, fn () => $this->unfinished($reservation, $apply),
            )));

        Reservation::query()
            ->where('status', 'cancelled')
            ->where('payment_status', Reservation::PAYMENT_UNPAID)
            ->whereNotNull('stripe_checkout_session_id')
            ->each(fn (Reservation $reservation) => $findings->push($this->guarded(
                'Reservation', $reservation, fn () => $this->cancelled($reservation, $apply, $refundOwed),
            )));

        return $findings;
    }

    /**
     * Turns a Stripe outage on one row into a finding instead of stopping the run.
     *
     * @param  callable(): ReconcileFinding  $inspect
     */
    private function guarded(string $kind, Purchase|Reservation $row, callable $inspect): ReconcileFinding
    {
        try {
            return $inspect();
        } catch (Throwable $e) {
            report($e);

            return new ReconcileFinding($kind, $row->id, (string) $row->stripe_checkout_session_id, 'Could not be checked: '.$e->getMessage(), ReconcileResult::Failed);
        }
    }

    /**
     * A pending/abandoned row: if Stripe took the money, give the customer what they paid for.
     */
    private function unfinished(Purchase|Reservation $row, bool $apply): ReconcileFinding
    {
        $kind = $row instanceof Purchase ? 'Pass' : 'Reservation';
        $session = (string) $row->stripe_checkout_session_id;
        $payment = $this->stripe->paymentSnapshot($session);

        if ($payment === null) {
            return new ReconcileFinding($kind, $row->id, $session, 'Stripe has no such session; left as it is', ReconcileResult::Unchanged);
        }

        if (! $payment->paid) {
            return new ReconcileFinding($kind, $row->id, $session, 'Never paid; left as it is', ReconcileResult::Unchanged);
        }

        if (! $apply) {
            return new ReconcileFinding($kind, $row->id, $session, 'Paid at Stripe but not fulfilled: would fulfil it', ReconcileResult::WouldChange);
        }

        $outcome = $this->fulfillment->fulfill($payment->session);

        return new ReconcileFinding($kind, $row->id, $session, 'Paid at Stripe but not fulfilled: '.$outcome->name, ReconcileResult::Changed);
    }

    /**
     * A cancelled reservation the old schema recorded as unpaid.
     */
    private function cancelled(Reservation $reservation, bool $apply, bool $refundOwed): ReconcileFinding
    {
        $session = (string) $reservation->stripe_checkout_session_id;
        $payment = $this->stripe->paymentSnapshot($session);

        if ($payment === null) {
            return new ReconcileFinding('Reservation', $reservation->id, $session, 'Stripe has no such session; left as it is', ReconcileResult::Unchanged);
        }

        if (! $payment->paid) {
            return new ReconcileFinding('Reservation', $reservation->id, $session, 'Never paid; left as it is', ReconcileResult::Unchanged);
        }

        $price = (int) $reservation->price_cents;
        $refunded = min($payment->refundedCents, $price);

        if ($refunded > 0) {
            $state = $reservation->paymentStatusAfterRefunding($refunded);
            $description = $state === Reservation::PAYMENT_REFUNDED
                ? 'Paid and fully refunded at Stripe'
                : "Paid, only {$refunded} of {$price} cents refunded at Stripe";

            if ($apply) {
                $reservation->forceFill([
                    'payment_status' => $state,
                    'refund_requested_cents' => $refunded,
                    'refunded_cents' => $refunded,
                    'refunded_at' => $reservation->refunded_at ?? now(),
                ])->save();
            }

            return new ReconcileFinding('Reservation', $reservation->id, $session, $description, $apply ? ReconcileResult::Changed : ReconcileResult::WouldChange);
        }

        // Paid and nothing came back. Cancelled early enough, that money was
        // owed (the refund must have failed); otherwise it was forfeited.
        if ($this->wasOwedRefund($reservation)) {
            if (! $refundOwed) {
                return new ReconcileFinding('Reservation', $reservation->id, $session, 'Paid, never refunded, and cancelled early enough to be owed a refund: needs --refund-owed (left as it is)', ReconcileResult::NeedsDecision);
            }

            if (! $apply) {
                return new ReconcileFinding('Reservation', $reservation->id, $session, 'Paid, never refunded, owed a refund: would refund it in full', ReconcileResult::WouldChange);
            }

            $reservation->forceFill(['payment_status' => Reservation::PAYMENT_PAID])->save();
            $outcome = $this->refunds->refundInFull($reservation->refresh());

            return new ReconcileFinding('Reservation', $reservation->id, $session, 'Paid, never refunded, owed a refund: '.$outcome->name, ReconcileResult::Changed);
        }

        if ($apply) {
            $reservation->forceFill(['payment_status' => Reservation::PAYMENT_PAID])->save();
        }

        return new ReconcileFinding('Reservation', $reservation->id, $session, 'Paid, cancelled too late for a refund: the money was kept', $apply ? ReconcileResult::Changed : ReconcileResult::WouldChange);
    }

    /**
     * Whether the cancellation came early enough (judged from when the row
     * was last touched, the nearest thing to a cancellation time the old
     * schema kept) for the customer to be owed their money back.
     */
    private function wasOwedRefund(Reservation $reservation): bool
    {
        $cutoffHours = ReservationSetting::current()->cancellation_cutoff_hours;

        return $reservation->updated_at !== null
            && $reservation->updated_at->lte($reservation->starts_at->copy()->subHours($cutoffHours));
    }
}
