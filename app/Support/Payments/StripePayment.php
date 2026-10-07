<?php

namespace App\Support\Payments;

use Stripe\Checkout\Session;

/**
 * What Stripe says happened to one Checkout session's money: whether it was
 * paid, and how much of it has been refunded since.
 */
final readonly class StripePayment
{
    public function __construct(
        public Session $session,
        public bool $paid,
        public int $refundedCents,
    ) {}
}
