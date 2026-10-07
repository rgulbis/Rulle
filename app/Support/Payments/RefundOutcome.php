<?php

namespace App\Support\Payments;

enum RefundOutcome
{
    /** Stripe confirmed the money is back with the customer. */
    case Refunded;

    /** The refund is owed and recorded, but Stripe did not confirm it yet; the retry command will pick it up. */
    case Pending;

    /** Nothing was ever collected through Stripe for this row, so there is nothing to give back. */
    case NothingToRefund;
}
