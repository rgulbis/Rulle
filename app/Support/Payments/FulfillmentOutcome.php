<?php

namespace App\Support\Payments;

enum FulfillmentOutcome
{
    /** The pass or reservation is paid for and live (now, or already was). */
    case Fulfilled;

    /** The payment arrived but the slot was no longer free; the booking is cancelled and the money is being refunded. */
    case SlotTaken;

    /** The payment arrived for a booking that was already cancelled; it is being refunded. */
    case RefundedAfterCancel;

    /** Stripe has not collected the money (yet), or the session belongs to nothing we sell. */
    case NotFulfilled;
}
