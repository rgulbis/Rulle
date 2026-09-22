<?php

namespace App\Http\Controllers\Staff;

use App\Events\OccupancyUpdated;
use App\Events\UserCheckInStatusUpdated;
use App\Http\Controllers\Controller;
use App\Models\CheckInEvent;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $user = User::where('qr_code', $validated['code'])->first();

        if (! $user) {
            return response()->json([
                'found' => false,
                'message' => 'No user matches this QR code.',
            ], 404);
        }

        $entering = $validated['mode'] === 'entry';

        // Staff picks the mode explicitly rather than the server inferring
        // it from the current state, so a code that's already inside can't
        // be scanned for entry again (e.g. a screenshot passed to a friend
        // while the real owner is still on-site) and vice versa.
        if ($entering === $user->checked_in) {
            return response()->json([
                'found' => true,
                'allowed' => false,
                'name' => $user->name,
                'message' => $entering ? 'Already checked in.' : 'Not currently checked in.',
            ], 409);
        }

        if ($entering) {
            if (! $user->hasVerifiedEmail()) {
                return response()->json([
                    'found' => true,
                    'allowed' => false,
                    'name' => $user->name,
                    'message' => 'Email not verified.',
                ], 403);
            }

            // Staff and admins always work here regardless of a private
            // reservation; the block is only for other customers.
            $activeReservation = $user->isCustomer() ? Reservation::activeNow() : null;
            $isReservationParty = $activeReservation && $activeReservation->includesParticipant($user);

            if ($activeReservation && ! $isReservationParty) {
                return response()->json([
                    'found' => true,
                    'allowed' => false,
                    'name' => $user->name,
                    'message' => 'Park privately reserved until '.$activeReservation->ends_at->format('H:i').'.',
                ], 403);
            }

            // Being part of the currently-running reservation is itself
            // what grants entry then — they already paid for this exact
            // slot, so it doesn't also require a separate subscription.
            if (! $isReservationParty) {
                if (! $user->hasActiveAccess()) {
                    return response()->json([
                        'found' => true,
                        'allowed' => false,
                        'name' => $user->name,
                        'message' => 'No active subscription.',
                    ], 403);
                }

                if (! $user->subscribed('default') && ($purchase = $user->activeOneTimePurchase()) && ! $purchase->subscriptionType->unlimited_entries) {
                    $purchase->decrement('visits_remaining');

                    if ($purchase->visits_remaining <= 0) {
                        $purchase->update(['status' => 'used_up']);
                    }
                }
            }
        }

        $user->checked_in = $entering;
        $user->save();

        CheckInEvent::create([
            'user_id' => $user->id,
            'checked_in' => $entering,
        ]);

        UserCheckInStatusUpdated::dispatch($user);
        OccupancyUpdated::dispatch();

        return response()->json([
            'found' => true,
            'allowed' => true,
            'name' => $user->name,
            'checked_in' => $user->checked_in,
        ]);
    }
}
