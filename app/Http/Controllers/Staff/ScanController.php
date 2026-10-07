<?php

namespace App\Http\Controllers\Staff;

use App\Events\OccupancyUpdated;
use App\Events\UserCheckInStatusUpdated;
use App\Http\Controllers\Controller;
use App\Models\CheckInEvent;
use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\User;
use App\Support\CheckIn\QrToken;
use App\Support\CheckIn\QrTokenProblem;
use App\Support\CheckIn\VerifiedQrToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ScanController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('staff/scan');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'mode' => ['required', 'in:entry,exit'],
        ]);

        $token = QrToken::verify($validated['code']);

        if ($token instanceof QrTokenProblem) {
            return response()->json([
                'found' => false,
                'message' => match ($token) {
                    QrTokenProblem::Invalid => __('No user matches this QR code.'),
                    QrTokenProblem::Expired => __('This QR code has expired. Ask the rider to wait for it to refresh.'),
                },
            ], $token === QrTokenProblem::Invalid ? 404 : 422);
        }

        $user = $token->user;
        $entering = $validated['mode'] === 'entry';

        // Reading the check-in state, validating, spending a visit and
        // recording the event are one transaction. Two scanners reading the
        // same QR code at once would otherwise both see "not inside" and both
        // let it in (two entries, two visits spent), or both spend the last
        // visit. The write lock is taken at BEGIN, so the second scan waits
        // and then sees the first one's event.
        [$status, $payload] = DB::transaction(fn () => $this->decide($user, $entering, $token));

        if ($status === 200) {
            // After the commit: a listener must never see an event that a
            // rollback could still take away.
            UserCheckInStatusUpdated::dispatch($user);
            OccupancyUpdated::dispatch();
        }

        return response()->json($payload, $status);
    }

    /**
     * Must run inside the transaction opened by store().
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function decide(User $user, bool $entering, VerifiedQrToken $token): array
    {
        $deny = fn (int $status, string $message): array => [$status, [
            'found' => true,
            'allowed' => false,
            'name' => $user->name,
            'message' => $message,
        ]];

        // Staff picks the mode explicitly rather than the server inferring
        // it from the current state, so a code that's already inside can't
        // be scanned for entry again (e.g. a screenshot passed to a friend
        // while the real owner is still on-site) and vice versa.
        if ($entering === $user->isCurrentlyCheckedIn()) {
            return $deny(409, $entering ? __('Already checked in.') : __('Not currently checked in.'));
        }

        $purchaseToSpend = null;

        if ($entering) {
            if (! $user->hasVerifiedEmail()) {
                return $deny(403, __('Email not verified.'));
            }

            // Staff and admins always work here regardless of a private
            // reservation; the block is only for other customers.
            $activeReservation = $user->isCustomer() ? Reservation::activeNow() : null;
            $isReservationParty = $activeReservation && $activeReservation->includesParticipant($user);

            if ($activeReservation && ! $isReservationParty) {
                return $deny(403, __('Park privately reserved until :time.', ['time' => $activeReservation->ends_at->format('H:i')]));
            }

            // Being part of the currently-running reservation is itself
            // what grants entry then — they already paid for this exact
            // slot, so it doesn't also require a separate subscription.
            if (! $isReservationParty) {
                if (! $user->hasActiveAccess()) {
                    return $deny(403, __('No active subscription.'));
                }

                if (! $user->subscribed('default') && ($purchase = $user->activeOneTimePurchase()) && ! $purchase->subscriptionType->unlimited_entries) {
                    $purchaseToSpend = $purchase;
                }
            }
        }

        // Everything above only reads, so a refused scan leaves the token
        // unspent and the rider can be scanned again once staff pick the
        // right mode. From here on it's a real entry or exit: the token is
        // spent first, so a copy of it (a screenshot) can't be used again.
        if (! QrToken::consume($token)) {
            return $deny(422, __('This QR code was already scanned. Ask the rider to wait for it to refresh.'));
        }

        // Can only fail if something other than a scan spent the last visit
        // since the check above (they're serialised by this transaction); the
        // rider then waits for a fresh code.
        if ($purchaseToSpend && ! $this->spendVisit($purchaseToSpend)) {
            return $deny(403, __('No active subscription.'));
        }

        CheckInEvent::create([
            'user_id' => $user->id,
            'checked_in' => $entering,
        ]);

        return [200, [
            'found' => true,
            'allowed' => true,
            'name' => $user->name,
            'checked_in' => $entering,
        ]];
    }

    /**
     * One conditional UPDATE: it only takes a visit if one is left, so the
     * counter can never go below zero even if something other than a scan
     * is spending visits too.
     */
    private function spendVisit(Purchase $purchase): bool
    {
        $spent = Purchase::whereKey($purchase->id)
            ->where('status', 'active')
            ->where('visits_remaining', '>', 0)
            ->decrement('visits_remaining');

        if ($spent === 0) {
            return false;
        }

        Purchase::whereKey($purchase->id)
            ->where('visits_remaining', '<=', 0)
            ->update(['status' => 'used_up']);

        return true;
    }
}
