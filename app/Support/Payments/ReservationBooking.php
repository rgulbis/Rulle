<?php

namespace App\Support\Payments;

use App\Models\Reservation;
use App\Models\ReservationSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ReservationBooking
{
    public function __construct(private Refunds $refunds) {}

    /**
     * Overlap check and insert in one transaction (SQLite takes its write
     * lock up front — see config/database.php), so two simultaneous requests
     * can't both pass the check. Returns null if the slot is taken.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createPending(array $attributes, CarbonInterface $startsAt, CarbonInterface $endsAt): ?Reservation
    {
        return DB::transaction(function () use ($attributes, $startsAt, $endsAt) {
            if (Reservation::overlapping($startsAt, $endsAt)->exists()) {
                return null;
            }

            return Reservation::create($attributes + [
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => 'pending',
            ]);
        });
    }

    /**
     * Cancels a reservation and settles the money, in that order of
     * *recording*: the cancellation and "refund owed" are saved together
     * first, and only then is Stripe called — so a Stripe outage leaves a
     * visible refund_failed row to retry, never a cancelled booking with the
     * payment quietly kept.
     *
     * `$refundRegardless` is for admin cancellations, which skip the
     * customer-facing cutoff window.
     *
     * @return 'cancelled'|'refunded'|'refund-pending'|'no-refund'|'cannot-cancel'
     */
    public function cancel(Reservation $reservation, bool $refundRegardless = false): string
    {
        $refundable = $refundRegardless
            || ReservationSetting::current()->isEligibleForCancellationRefund($reservation->starts_at);

        $outcome = DB::transaction(function () use ($reservation, $refundable) {
            $reservation->refresh();

            if ($reservation->status === 'cancelled') {
                return 'cancelled';
            }

            if ($reservation->status === 'pending') {
                $reservation->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

                return 'cancelled';
            }

            if (! $reservation->starts_at->isFuture()) {
                return 'cannot-cancel';
            }

            $owesRefund = $refundable && $reservation->payment_status === 'paid';

            $reservation->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'payment_status' => $owesRefund ? 'refund_pending' : $reservation->payment_status,
            ])->save();

            return $owesRefund ? 'refund-due' : 'no-refund';
        });

        if ($outcome !== 'refund-due') {
            return $outcome;
        }

        return $this->refunds->issue($reservation) ? 'refunded' : 'refund-pending';
    }
}
