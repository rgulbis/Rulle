<?php

namespace App\Console\Commands;

use App\Models\Purchase;
use App\Models\Reservation;
use Illuminate\Console\Command;

class ExpireAbandonedCheckouts extends Command
{
    protected $signature = 'payments:expire-abandoned';

    protected $description = 'Close passes and reservations whose Stripe Checkout was never finished';

    /**
     * Stripe expires an unfinished Checkout Session 24 hours after it was
     * created. An hour on top, so a row is never closed while its session
     * could still be paid.
     */
    private const GRACE_HOURS = 25;

    /**
     * The `checkout.session.expired` webhook does this too, but only if that
     * event is enabled on the Stripe endpoint and delivered. This is the
     * backstop, so unpaid rows never pile up in the Payments list. Same
     * transitions as the webhook, and as safe: a pass is `abandoned`, not
     * deleted, so a payment that still shows up late is honoured; a
     * reservation is `cancelled`.
     */
    public function handle(): int
    {
        $cutoff = now()->subHours(self::GRACE_HOURS);

        $purchases = Purchase::where('status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->update(['status' => 'abandoned']);

        $reservations = Reservation::where('status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->update(['status' => 'cancelled']);

        $this->info("Closed {$purchases} abandoned pass checkouts and {$reservations} abandoned reservation checkouts.");

        return self::SUCCESS;
    }
}
