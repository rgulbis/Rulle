<?php

namespace App\Http\Controllers\Reservations;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\ReservationParticipant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The invited person's side of a reservation invitation. Only an accepted
 * invitation counts towards the paid group size and opens the group chat.
 */
class ReservationInvitationController extends Controller
{
    public function accept(Request $request, Reservation $reservation): RedirectResponse
    {
        $user = $request->user();

        // Whether there is still room is decided with the write lock held:
        // the invitations were not counted when they were sent, so two
        // people accepting the last seat at once must not both get it.
        $outcome = DB::transaction(function () use ($reservation, $user) {
            $reservation->refresh();
            $invitation = $reservation->invitationFor($user);

            if ($invitation === null || $invitation->status === ReservationParticipant::DECLINED) {
                return 'invitation-unavailable';
            }

            if ($invitation->status === ReservationParticipant::ACCEPTED) {
                return 'invitation-accepted';
            }

            if (! $reservation->isUpcomingOrOngoing()) {
                return 'invitation-unavailable';
            }

            if (! $reservation->hasParticipantCapacity()) {
                return 'invitation-full';
            }

            $reservation->invitations()->updateExistingPivot($user->id, [
                'status' => ReservationParticipant::ACCEPTED,
                'responded_at' => now(),
            ]);

            return 'invitation-accepted';
        });

        return to_route('reservations.index')->with('status', $outcome);
    }

    public function decline(Request $request, Reservation $reservation): RedirectResponse
    {
        $user = $request->user();

        $invitation = $reservation->invitationFor($user);
        abort_if($invitation === null, 404);

        // Someone who already accepted leaves instead (see
        // ReservationController::leave) — declining isn't a way out of that.
        if ($invitation->status === ReservationParticipant::ACCEPTED) {
            return to_route('reservations.index')->with('status', 'invitation-unavailable');
        }

        $reservation->invitations()
            ->wherePivot('status', ReservationParticipant::INVITED)
            ->updateExistingPivot($user->id, [
                'status' => ReservationParticipant::DECLINED,
                'responded_at' => now(),
            ]);

        return to_route('reservations.index')->with('status', 'invitation-declined');
    }
}
