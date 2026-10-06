<?php

namespace App\Http\Controllers\Staff;

use App\Events\OccupancyUpdated;
use App\Events\UserCheckInStatusUpdated;
use App\Http\Controllers\Controller;
use App\Models\CheckInEvent;
use App\Models\Reservation;
use App\Models\User;
use App\Support\EntryToken;
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
            'code' => ['required', 'string', 'max:200'],
            'mode' => ['required', 'in:entry,exit'],
        ]);

        $user = EntryToken::resolve($validated['code']);

        if (! $user) {
            return response()->json([
                'found' => false,
                'message' => __('This QR code is invalid or has expired — ask the customer to reload their pass.'),
            ], 404);
        }

        $entering = $validated['mode'] === 'entry';

        // The whole decision — current state, access check, visit decrement
        // and the check-in row — runs in one transaction. SQLite takes its
        // write lock when it begins (config/database.php), so two scans of
        // the same person at once can't both read "not inside", both pass,
        // and both spend a visit.
        [$status, $payload] = DB::transaction(fn () => $this->decide($user, $entering, $validated['code']));

        if ($status === 200) {
            UserCheckInStatusUpdated::dispatch($user);
            OccupancyUpdated::dispatch();
        }

        return response()->json($payload, $status);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function decide(User $user, bool $entering, string $code): array
    {
        $denied = fn (int $status, string $message) => [$status, [
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
            return $denied(409, $entering ? __('Already checked in.') : __('Not currently checked in.'));
        }

        if ($entering) {
            // Checked before anything is spent (a denial doesn't roll the
            // transaction back); the code is only *claimed* at the end.
            if (EntryToken::alreadyUsed($code)) {
                return $denied(409, __('This QR code was already used — ask the customer to reload their pass.'));
            }

            if (! $user->hasVerifiedEmail()) {
                return $denied(403, __('Email not verified.'));
            }

            // Staff and admins always work here regardless of a private
            // reservation; the block is only for other customers.
            $activeReservation = $user->isCustomer() ? Reservation::activeNow() : null;
            $isReservationParty = $activeReservation && $activeReservation->includesParticipant($user);

            if ($activeReservation && ! $isReservationParty) {
                return $denied(403, __('Park privately reserved until :time.', ['time' => $activeReservation->ends_at->format('H:i')]));
            }

            // Being part of the currently-running reservation is itself
            // what grants entry then — they already paid for this exact
            // slot, so it doesn't also require a separate subscription.
            if (! $isReservationParty) {
                if (! $user->hasActiveAccess()) {
                    return $denied(403, __('No active subscription.'));
                }

                if (! $user->subscribed('default') && ($purchase = $user->activeOneTimePurchase()) && ! $purchase->subscriptionType->unlimited_entries) {
                    $purchase->decrement('visits_remaining');

                    if ($purchase->visits_remaining <= 0) {
                        $purchase->update(['status' => 'used_up']);
                    }
                }
            }

            // One code, one entry — claimed only once access is granted, so a
            // denied scan doesn't burn the customer's code.
            EntryToken::claim($code);
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
}
