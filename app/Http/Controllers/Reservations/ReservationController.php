<?php

namespace App\Http\Controllers\Reservations;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\ReservationSetting;
use App\Models\User;
use App\Support\CheckInOccupancy;
use App\Support\Payments\CheckoutFulfillment;
use App\Support\Payments\ReservationBooking;
use App\Support\Payments\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

class ReservationController extends Controller
{
    public function __construct(
        private ReservationBooking $booking,
        private CheckoutFulfillment $fulfillment,
        private StripeGateway $stripe,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $settings = ReservationSetting::current();

        return Inertia::render('reservations/index', [
            'settings' => $settings->only([
                'price_cents_per_person_per_hour',
                'min_group_size',
                'max_group_size',
                'min_duration_minutes',
                'max_duration_minutes',
                'opening_time',
                'closing_time',
            ]),
            // A "typical day" busyness shape (hour => check-in count,
            // collapsed across all history) shown as a reference line
            // behind the booking timeline — not tied to the selected date.
            'peakHours' => CheckInOccupancy::typicalCheckInsByHour(),
            // Time ranges only — who booked a slot isn't anyone else's
            // business, just that the park is unavailable then.
            'upcoming' => Reservation::where('status', 'active')
                ->where('ends_at', '>', now())
                ->orderBy('starts_at')
                ->get(['id', 'starts_at', 'ends_at']),
            // Reservations the user owns, or was added to as a participant —
            // shaped explicitly so a participant's view doesn't leak the
            // owner's raw user_id, just whether *they* are the owner.
            'mine' => Reservation::where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhereHas('participants', fn ($q) => $q->whereKey($user->id));
            })
                ->where('ends_at', '>', now())
                ->where('status', '!=', 'cancelled')
                ->with(['participants:id,name,email', 'invitedUsers:id,name,email'])
                ->orderBy('starts_at')
                ->get(['id', 'user_id', 'starts_at', 'ends_at', 'group_size', 'price_cents', 'status'])
                ->map(fn (Reservation $reservation) => [
                    'id' => $reservation->id,
                    'starts_at' => $reservation->starts_at,
                    'ends_at' => $reservation->ends_at,
                    'group_size' => $reservation->group_size,
                    'price_cents' => $reservation->price_cents,
                    'status' => $reservation->status,
                    'is_owner' => $reservation->user_id === $user->id,
                    'participants' => $reservation->participants,
                    // Only the owner needs to see who hasn't answered yet.
                    'invited' => $reservation->user_id === $user->id ? $reservation->invitedUsers : [],
                ]),
            // Reservations other people have invited *this* user to — nothing
            // is shared with them (or visible in their chat/groups) until they
            // accept.
            'invitations' => Reservation::whereHas('invitedUsers', fn ($q) => $q->whereKey($user->id))
                ->where('status', 'active')
                ->where('ends_at', '>', now())
                ->with('user:id,name')
                ->orderBy('starts_at')
                ->get()
                ->map(fn (Reservation $reservation) => [
                    'id' => $reservation->id,
                    'starts_at' => $reservation->starts_at,
                    'ends_at' => $reservation->ends_at,
                    'group_size' => $reservation->group_size,
                    'owner_name' => $reservation->user->name,
                ]),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request): BaseResponse
    {
        $settings = ReservationSetting::current();

        $validated = $request->validate([
            'starts_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => [
                'required',
                'integer',
                "min:{$settings->min_duration_minutes}",
                "max:{$settings->max_duration_minutes}",
            ],
            'group_size' => [
                'required',
                'integer',
                "min:{$settings->min_group_size}",
                "max:{$settings->max_group_size}",
            ],
        ]);

        $startsAt = Carbon::parse($validated['starts_at']);
        $endsAt = $startsAt->copy()->addMinutes($validated['duration_minutes']);

        $startMinutes = $startsAt->hour * 60 + $startsAt->minute;
        $endMinutes = $startMinutes + $validated['duration_minutes'];

        if ($startMinutes < $settings->openingMinutes() || $endMinutes > $settings->closingMinutes()) {
            return back()->withErrors([
                'starts_at' => __('The park is only open :opening–:closing.', ['opening' => $settings->opening_time, 'closing' => $settings->closing_time]),
            ]);
        }

        $user = $request->user();
        $priceCents = $settings->priceFor($validated['duration_minutes'], $validated['group_size']);

        $reservation = $this->booking->createPending([
            'user_id' => $user->id,
            'group_size' => $validated['group_size'],
            'price_cents' => $priceCents,
        ], $startsAt, $endsAt);

        if (! $reservation) {
            return back()->withErrors([
                'starts_at' => __('That time overlaps with an existing reservation.'),
            ]);
        }

        return $this->startCheckout($user, $reservation);
    }

    /**
     * Lets someone who abandoned Stripe Checkout (closed the tab instead of
     * using Stripe's own back link) come back and finish paying for a
     * reservation that's still sitting pending, instead of it being a dead
     * end where cancelling is the only option.
     */
    public function resume(Request $request, Reservation $reservation): BaseResponse
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);
        abort_unless($reservation->status === 'pending', 422, 'This reservation is no longer pending.');

        if ($reservation->stripe_checkout_session_id) {
            $existing = $this->stripe->checkoutSession($reservation->stripe_checkout_session_id);

            if ($existing->status === 'open') {
                return Inertia::location($existing->url);
            }

            // Already paid (the customer closed the tab before the success
            // redirect): settle that instead of charging them a second time.
            if ($existing->status === 'complete' && $existing->payment_status === 'paid') {
                $this->fulfillment->fulfill($existing->id, is_string($existing->payment_intent) ? $existing->payment_intent : null);

                return redirect()->route('reservations.index');
            }
        }

        return $this->startCheckout($request->user(), $reservation);
    }

    private function startCheckout(User $user, Reservation $reservation): BaseResponse
    {
        $durationMinutes = $reservation->starts_at->diffInMinutes($reservation->ends_at);

        $successUrl = route('reservations.success').'?session_id={CHECKOUT_SESSION_ID}';
        // Stripe sends the browser here by GET if the customer backs out. That
        // must not change anything, so it's just the reservations page, where
        // the still-pending booking can be resumed or cancelled explicitly.
        $cancelUrl = route('reservations.index');

        $session = $this->stripe->createReservationCheckout(
            $user,
            $reservation->price_cents,
            'Park reservation ('.$durationMinutes.' min, '.$reservation->group_size.' people)',
            $successUrl,
            $cancelUrl,
            // Matches the pending-reservation grace window in
            // Reservation::scopeOverlapping() (and is Stripe's minimum), so an
            // abandoned checkout stops holding the slot on time.
            now()->addMinutes(30)->getTimestamp(),
        );

        $reservation->update(['stripe_checkout_session_id' => $session['id']]);

        return Inertia::location($session['url']);
    }

    /**
     * Where Stripe sends the browser after paying. Not the source of truth —
     * the `checkout.session.completed` webhook is — this just reports the
     * outcome (fulfilling it first if the webhook hasn't arrived yet). Only
     * the reservation's own owner can look up a session here.
     */
    public function success(Request $request): RedirectResponse
    {
        $sessionId = $request->query('session_id');
        $status = 'reservation-incomplete';

        $reservation = is_string($sessionId)
            ? Reservation::where('stripe_checkout_session_id', $sessionId)
                ->where('user_id', $request->user()->id)
                ->first()
            : null;

        if ($reservation) {
            $session = $this->stripe->checkoutSession($sessionId);

            if ($session->status === 'complete' && $session->payment_status === 'paid') {
                $paymentIntent = is_string($session->payment_intent) ? $session->payment_intent : ($session->payment_intent->id ?? null);
                $this->fulfillment->fulfill($sessionId, $paymentIntent);
            }

            $reservation->refresh();

            if ($reservation->status === 'active') {
                $status = 'reservation-complete';
            } elseif ($reservation->status === 'cancelled' && $reservation->paid_at) {
                $status = 'reservation-slot-taken';
            }
        }

        return redirect()->route('reservations.index')->with('status', $status);
    }

    public function cancel(Request $request, Reservation $reservation): RedirectResponse
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);

        $status = match ($this->booking->cancel($reservation)) {
            'refunded' => 'reservation-cancelled-refunded',
            'refund-pending' => 'reservation-cancelled-refund-pending',
            'no-refund' => 'reservation-cancelled-no-refund',
            'cannot-cancel' => 'reservation-cannot-cancel',
            default => 'reservation-cancelled',
        };

        return redirect()->route('reservations.index')->with('status', $status);
    }

    /**
     * Invites someone to the reservation — they only become part of it (and
     * its chat) once they accept. Capacity is checked inside the transaction
     * that adds the invitation, so two simultaneous requests for the last
     * seat can't both succeed.
     */
    public function addParticipant(Request $request, Reservation $reservation): RedirectResponse
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);
        abort_unless($reservation->status === 'active', 422, 'Only a paid reservation can have a group.');
        abort_if($reservation->ends_at->isPast(), 422, 'This reservation has already ended.');

        $validated = $request->validate([
            'user_id' => [
                'required',
                // Customers who can actually use the invitation: verified
                // (the scanner refuses unverified accounts anyway) and not a
                // closed account (soft-deleted users are excluded by `deleted_at`).
                Rule::exists('users', 'id')
                    ->where('role', 'user')
                    ->whereNotNull('email_verified_at')
                    ->whereNull('deleted_at'),
                Rule::notIn([$reservation->user_id]),
            ],
        ]);

        $result = DB::transaction(function () use ($reservation, $validated) {
            $reservation->refresh();

            if ($reservation->allParticipants()->whereKey($validated['user_id'])->exists()) {
                return 'already';
            }

            if (! $reservation->hasParticipantCapacity()) {
                return 'full';
            }

            $reservation->allParticipants()->attach($validated['user_id'], ['status' => 'invited']);

            return 'invited';
        });

        if ($result === 'full') {
            return back()->withErrors([
                'user_id' => __('This reservation is already at its paid group size.'),
            ]);
        }

        return back()->with('status', 'participant-invited');
    }

    /**
     * Withdraws an invitation or removes someone who already joined.
     */
    public function removeParticipant(Request $request, Reservation $reservation, User $participant): RedirectResponse
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);

        $reservation->allParticipants()->detach($participant->id);

        return back()->with('status', 'participant-removed');
    }

    public function acceptInvitation(Request $request, Reservation $reservation): RedirectResponse
    {
        $user = $request->user();

        $accepted = DB::transaction(function () use ($reservation, $user) {
            $reservation->refresh();

            $invited = $reservation->invitedUsers()->whereKey($user->id)->exists();

            if (! $invited || $reservation->status !== 'active' || $reservation->ends_at->isPast()) {
                return false;
            }

            $reservation->allParticipants()->updateExistingPivot($user->id, [
                'status' => 'accepted',
                'responded_at' => now(),
            ]);

            return true;
        });

        abort_unless($accepted, 404);

        return redirect()->route('reservations.index')->with('status', 'invitation-accepted');
    }

    public function declineInvitation(Request $request, Reservation $reservation): RedirectResponse
    {
        $user = $request->user();

        abort_unless($reservation->invitedUsers()->whereKey($user->id)->exists(), 404);

        $reservation->allParticipants()->detach($user->id);

        return redirect()->route('reservations.index')->with('status', 'invitation-declined');
    }

    /**
     * A named participant taking themselves off someone else's reservation.
     * The owner can't leave their own booking — that's what cancelling is.
     */
    public function leave(Request $request, Reservation $reservation): RedirectResponse
    {
        $user = $request->user();
        abort_if($reservation->isOwnedBy($user), 403);
        abort_unless($reservation->participants()->whereKey($user->id)->exists(), 403);

        $reservation->allParticipants()->detach($user->id);

        // Not back(): leaving from the group chat page would bounce straight
        // into a 403, since that chat is no longer theirs to view.
        return to_route('reservations.index')->with('status', 'reservation-left');
    }

    public function searchUsers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:100'],
        ]);

        // `%` and `_` are LIKE wildcards: unescaped, a query of "%%" matched
        // every customer. They're searched for literally instead.
        $term = addcslashes($validated['q'], '\\%_');

        $matches = User::query()
            ->where('role', 'user')
            ->whereNotNull('email_verified_at')
            ->where('id', '!=', $request->user()->id)
            // By display name only: matching on email would let anyone probe
            // which addresses/domains are registered.
            ->whereRaw("name like ? escape '\\'", ["%{$term}%"])
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                // Masked, not omitted — enough to disambiguate same-named
                // people without letting any customer harvest every other
                // customer's real email address through this search.
                'email' => User::maskEmail($user->email),
            ]);

        return response()->json($matches);
    }
}
