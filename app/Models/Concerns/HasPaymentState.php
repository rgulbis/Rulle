<?php

namespace App\Models\Concerns;

/**
 * The money side of a Purchase or Reservation, kept apart from its lifecycle
 * `status` (pending / active / cancelled ...). A row has been paid for if
 * `payment_status` says so, whatever happened to the booking afterwards.
 *
 * The using model declares the columns (`payment_status`, `price_cents`,
 * `refund_requested_cents`, `refunded_cents`, `refunded_at`).
 */
trait HasPaymentState
{
    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_REFUNDED = 'refunded';

    public const PAYMENT_PARTIALLY_REFUNDED = 'partially_refunded';

    public const PAYMENT_REFUND_FAILED = 'refund_failed';

    /**
     * Money was collected for this row at some point.
     */
    public function hasBeenPaid(): bool
    {
        return $this->payment_status !== self::PAYMENT_UNPAID;
    }

    /**
     * What is still owed back to the customer: the recorded refund intent
     * minus what Stripe has confirmed so far.
     */
    public function outstandingRefundCents(): int
    {
        return max(0, ($this->refund_requested_cents ?? 0) - $this->refunded_cents);
    }

    /**
     * Net money kept for this row - what it contributes to revenue.
     */
    public function netPaidCents(): int
    {
        return $this->hasBeenPaid() ? max(0, (int) $this->price_cents - $this->refunded_cents) : 0;
    }

    /**
     * Payment status implied by how much has been refunded so far.
     */
    public function paymentStatusAfterRefunding(int $refundedCents): string
    {
        if ($refundedCents <= 0) {
            return self::PAYMENT_PAID;
        }

        return $refundedCents >= (int) $this->price_cents
            ? self::PAYMENT_REFUNDED
            : self::PAYMENT_PARTIALLY_REFUNDED;
    }
}
