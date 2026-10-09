<?php

namespace App\Support\Payments;

use App\Models\Purchase;
use App\Models\Reservation;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * Refunds are a two-step promise, not a single Stripe call:
 *
 *  1. The intent (`refund_requested_cents`) is written to the database first.
 *  2. Only then is Stripe called, with an idempotency key, and the result is
 *     recorded (`refunded_cents`, `refunded_at`, `payment_status`).
 *
 * If Stripe is down or the request dies half way, the row is left owing a
 * refund - flagged `refund_failed` when Stripe answered with an error - and
 * `payments:retry-refunds` keeps trying. Money is never silently kept.
 */
class Refunds
{
    public function __construct(private readonly StripeGateway $stripe) {}

    /**
     * Refund everything the customer paid for this row.
     */
    public function refundInFull(Purchase|Reservation $payable): RefundOutcome
    {
        if (! $payable->stripe_checkout_session_id || ! $payable->hasBeenPaid()) {
            return RefundOutcome::NothingToRefund;
        }

        $payable->forceFill(['refund_requested_cents' => $payable->price_cents])->save();

        return $this->settle($payable);
    }

    /**
     * Send whatever is still owed for a recorded refund intent to Stripe.
     * Safe to call repeatedly and concurrently.
     */
    public function settle(Purchase|Reservation $payable): RefundOutcome
    {
        // Work from what the database says right now, not from a possibly
        // stale model: the compare-and-swap below depends on it.
        $payable->refresh();

        $outstanding = $payable->outstandingRefundCents();

        if ($outstanding === 0) {
            return RefundOutcome::Refunded;
        }

        try {
            $session = $this->stripe->retrieveCheckoutSession((string) $payable->stripe_checkout_session_id);

            if (! $session->payment_intent) {
                return $this->fail($payable, 'The checkout session has no payment to refund.');
            }

            $refunded = $this->stripe->createRefund(
                (string) $session->payment_intent,
                $outstanding,
                $this->idempotencyKey($payable, $outstanding),
            )->amount;
        } catch (ApiErrorException $e) {
            // Stripe saying the charge is already fully refunded means an
            // earlier attempt got through and only our bookkeeping was lost.
            if ($e->getStripeCode() === 'charge_already_refunded') {
                $refunded = $outstanding;
            } else {
                return $this->fail($payable, $e->getMessage());
            }
        }

        $this->recordRefunded($payable, $refunded);

        return RefundOutcome::Refunded;
    }

    /**
     * One key per (row, amount): the same refund retried gets the same key,
     * a different amount a different one.
     */
    private function idempotencyKey(Purchase|Reservation $payable, int $amountCents): string
    {
        return sprintf('refund-%s-%d-%d', $payable->getTable(), $payable->getKey(), $amountCents);
    }

    /**
     * Compare-and-swap on `refunded_cents`: if another process recorded the
     * same refund first (retry command vs. a request), this one is a no-op
     * instead of counting the money twice.
     */
    private function recordRefunded(Purchase|Reservation $payable, int $refundedNowCents): void
    {
        $before = $payable->refunded_cents;
        $total = min((int) $payable->price_cents, $before + $refundedNowCents);

        $updated = $payable->newQuery()
            ->whereKey($payable->getKey())
            ->where('refunded_cents', $before)
            ->update([
                'refunded_cents' => $total,
                'refunded_at' => now(),
                'payment_status' => $payable->paymentStatusAfterRefunding($total),
            ]);

        $payable->refresh();

        if ($updated === 0) {
            Log::info('Refund was already recorded by another process.', ['table' => $payable->getTable(), 'id' => $payable->getKey()]);
        }
    }

    private function fail(Purchase|Reservation $payable, string $reason): RefundOutcome
    {
        $payable->forceFill(['payment_status' => $payable::PAYMENT_REFUND_FAILED])->save();

        Log::error('Stripe refund failed; it will be retried.', [
            'table' => $payable->getTable(),
            'id' => $payable->getKey(),
            'reason' => $reason,
        ]);

        return RefundOutcome::Pending;
    }
}
