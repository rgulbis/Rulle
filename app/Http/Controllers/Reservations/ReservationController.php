<?php

namespace App\Http\Controllers\Reservations;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\ReservationParticipant;
use App\Models\ReservationSetting;
use App\Models\User;
use App\Support\CheckInOccupancy;
use App\Support\Payments\CheckoutFulfillment;
use App\Support\Payments\FulfillmentOutcome;
use App\Support\Payments\RefundOutcome;
use App\Support\Payments\Refunds;
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
        private readonly StripeGateway $stripe,
        private readonly CheckoutFulfillment $fulfillment,
        private readonly Refunds $refunds,
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
            // Reservations the user owns, or accepted an invitation to —
            // shaped explicitly so a participant's view doesn't leak the
            // owner's raw user_id, just whether *they* are the owner. Only
            // the owner sees who is merely invited or declined; everyone
            // else sees who is actually in.
            'mine' => Reservation::involving($user)
                ->where('ends_at', '>', now())
                ->where('status', '!=', 'cancelled')
                ->with('invitationRows.user:id,name')
                ->orderBy('starts_at')
                ->get(['id', 'user_id', 'starts_at', 'ends_at', 'group_size', 'price_cents', 'status'])
                ->map(function (Reservation $reservation) use ($user) {
                    $isOwner = $reservation->user_id === $user->id;

                    return [
                        'id' => $reservation->id,
                        'starts_at' => $reservation->starts_at,
                        'ends_at' => $reservation->ends_at,
                        'group_size' => $reservation->group_size,
                        'price_cents' => $reservation->price_cents,
                        'status' => $reservation->status,
                        'is_owner' => $isOwner,
                        'participants' => $reservation->invitationRows
                            ->filter(fn (ReservationParticipant $row) => $isOwner || $row->status === ReservationParticipant::ACCEPTED)
                            ->map(fn (ReservationParticipant $row) => [
                                'id' => $row->user_id,
                                'name' => $row->user->name,
                                'status' => $row->status,
                            ])
                            ->values(),
                    ];
                }),
            // Invitations to someone else's reservation that are still open
            // to answer: the reservation is paid for and has not ended.
            'invitations' => Reservation::query()
                ->where('status', 'active')
                ->where('ends_at', '>', now())
                ->whereHas('invitations', fn ($query) => $query
                    ->whereKey($user->id)
                    ->where('reservation_users.status', ReservationParticipant::INVITED))
                ->with('user:id,name')
                ->orderBy('starts_at')
                ->get(['id', 'user_id', 'starts_at', 'ends_at', 'group_size'])
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
            'starts_at' => [
                'required',
                'date',
                'after:now',
                // Far-future dates would just sit in the calendar (and in
                // Stripe) with nothing to ever pay for or use them.
                'before:'.now()->addMonths(Reservation::MAX_MONTHS_AHEAD)->toDateTimeString(),
            ],
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

        // The overlap check and the insert are one transaction: with SQLite's
        // IMMEDIATE mode the write lock is taken at BEGIN, so a second request
        // for the same slot waits here and then sees the first one's row.
        // Checked outside it, two requests could both find the slot free. The
        // Stripe call stays outside — the lock must not be held over a
        // network round trip.
        $reservation = DB::transaction(function () use ($startsAt, $endsAt, $user, $validated, $priceCents) {
            // One unpaid hold at a time: otherwise a single account could
            // reserve slot after slot without paying and keep the whole
            // calendar blocked. Checked inside the same transaction as the
            // insert, so parallel requests can't both pass it.
            if (Reservation::holdingSlotFor($user)->exists()) {
                return 'has-pending';
            }

            if (Reservation::overlapping($startsAt, $endsAt)->exists()) {
                return null;
            }

            return Reservation::create([
                'user_id' => $user->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'group_size' => $validated['group_size'],
                'price_cents' => $priceCents,
                'status' => 'pending',
            ]);
        });

        if ($reservation === 'has-pending') {
            return back()->withErrors([
                'starts_at' => __('You already have a reservation waiting for payment. Pay for it or cancel it before booking another.'),
            ]);
        }

        if ($reservation === null) {
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
        abort_unless($reservation->status === 'pending', 422, __('This reservation is no longer pending.'));

        if ($reservation->stripe_checkout_session_id) {
            $existing = $this->stripe->retrieveCheckoutSession($reservation->stripe_checkout_session_id);

            if ($existing->status === 'open') {
                return Inertia::location($existing->url);
            }
        }

        return $this->startCheckout($request->user(), $reservation);
    }

    private function startCheckout(User $user, Reservation $reservation): BaseResponse
    {
        $successUrl = route('reservations.success').'?session_id={CHECKOUT_SESSION_ID}';
        // A read-only landing page: leaving Stripe by the back link changes
        // nothing, the reservation stays pending until it is paid, cancelled
        // explicitly, or Stripe expires the session.
        $cancelUrl = route('reservations.checkout-cancelled');

        $session = $this->stripe->createReservationCheckout($user, $reservation, $successUrl, $cancelUrl);

        $reservation->update(['stripe_checkout_session_id' => $session->id]);

        return Inertia::location($session->url);
    }

    /**
     * Where Stripe sends the customer after paying. Fulfilment itself is the
     * webhook's job; this runs the same idempotent code so the customer sees
     * the result at once, and only for a session that is their own.
     */
    public function success(Request $request): RedirectResponse
    {
        $sessionId = $request->query('session_id');

        $owned = is_string($sessionId) && Reservation::where('stripe_checkout_session_id', $sessionId)
            ->where('user_id', $request->user()->id)
            ->exists();

        $status = 'reservation-incomplete';

        if ($owned) {
            $session = $this->stripe->findCheckoutSession($sessionId);
            $outcome = $session ? $this->fulfillment->fulfill($session) : FulfillmentOutcome::NotFulfilled;

            $status = match ($outcome) {
                FulfillmentOutcome::Fulfilled => 'reservation-complete',
                FulfillmentOutcome::SlotTaken => 'reservation-slot-taken',
                FulfillmentOutcome::RefundedAfterCancel => 'reservation-cancelled-refunded',
                FulfillmentOutcome::NotFulfilled => 'reservation-incomplete',
            };
        }

        return redirect()->route('reservations.index')->with('status', $status);
    }

    /**
     * Stripe's cancel_url. Strictly read-only — a GET that changes state can
     * be triggered by a link, a prefetch or a crawler.
     */
    public function checkoutCancelled(): RedirectResponse
    {
        return redirect()->route('reservations.index')->with('status', 'reservation-incomplete');
    }

    /**
     * The customer calling off their own reservation (DELETE).
     */
    public function cancel(Request $request, Reservation $reservation): RedirectResponse
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);

        // Frees up the slot immediately instead of waiting out the pending
        // grace window in Reservation::scopeOverlapping(). Nothing was ever
        // charged for a pending reservation, so there's nothing to refund
        // (and if the payment does land afterwards, fulfilment refunds it).
        if ($reservation->status === 'pending') {
            Reservation::whereKey($reservation->id)->where('status', 'pending')->update(['status' => 'cancelled']);

            return redirect()->route('reservations.index')->with('status', 'reservation-cancelled');
        }

        if ($reservation->status !== 'active') {
            return redirect()->route('reservations.index')->with('status', 'reservation-cancelled');
        }

        if (! $reservation->starts_at->isFuture()) {
            return redirect()->route('reservations.index')->with('status', 'reservation-cannot-cancel');
        }

        // A paid reservation can still be called off if the customer
        // changes their mind, as long as it hasn't started yet. Whether
        // that refunds them depends on how close to the start time it is.
        $cancelled = Reservation::whereKey($reservation->id)->where('status', 'active')->update(['status' => 'cancelled']);

        if ($cancelled === 0) {
            return redirect()->route('reservations.index')->with('status', 'reservation-cancelled');
        }

        // Too close to the start: the slot is freed but the payment is kept.
        if (! ReservationSetting::current()->isEligibleForCancellationRefund($reservation->starts_at)) {
            return redirect()->route('reservations.index')->with('status', 'reservation-cancelled-no-refund');
        }

        $outcome = $this->refunds->refundInFull($reservation->refresh());

        return redirect()->route('reservations.index')->with('status', match ($outcome) {
            RefundOutcome::Refunded => 'reservation-cancelled-refunded',
            RefundOutcome::Pending => 'reservation-cancelled-refund-pending',
            // Eligible, but no online payment is on record to give back.
            RefundOutcome::NothingToRefund => 'reservation-cancelled-no-payment',
        });
    }

    /**
     * Invites someone to a reservation. They are not on it until they
     * accept (see ReservationInvitationController), so an invitation takes
     * no seat of the paid group size and gives no access to the group chat.
     */
    public function addParticipant(Request $request, Reservation $reservation): RedirectResponse
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);
        abort_unless($reservation->isUpcomingOrOngoing(), 422, __('Only a paid reservation that has not ended can have participants.'));

        $validated = $request->validate([
            'user_id' => [
                'required',
                // Only a customer who has confirmed their email: an invitation
                // to an unverified address would go to somebody who may not
                // even be the owner of it.
                Rule::exists('users', 'id')
                    ->where('role', 'user')
                    ->whereNotNull('email_verified_at')
                    ->withoutTrashed(),
                Rule::notIn([$reservation->user_id]),
            ],
        ]);

        // Looking at the existing invitations, counting the accepted ones and
        // adding one is a single transaction, otherwise two simultaneous
        // requests both see "one seat left" and both invite for it.
        $outcome = DB::transaction(function () use ($reservation, $validated) {
            $existing = $reservation->invitations()->whereKey($validated['user_id'])->first()?->pivot;

            if ($existing !== null) {
                return $existing->status === ReservationParticipant::DECLINED ? 'declined' : 'already';
            }

            if (! $reservation->hasParticipantCapacity()) {
                return 'full';
            }

            $reservation->invitations()->attach($validated['user_id'], ['status' => ReservationParticipant::INVITED]);

            return 'invited';
        });

        if ($outcome === 'full') {
            return back()->withErrors([
                'user_id' => __('This reservation is already at its paid group size.'),
            ]);
        }

        if ($outcome === 'declined') {
            return back()->withErrors([
                'user_id' => __('This person already declined an invitation to this reservation.'),
            ]);
        }

        return back()->with('status', 'participant-invited');
    }

    /**
     * Takes someone off the reservation, or withdraws an invitation that
     * has not been answered. A declined invitation stays on record, so the
     * owner can't keep re-inviting someone who said no.
     */
    public function removeParticipant(Request $request, Reservation $reservation, User $participant): RedirectResponse
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);

        $reservation->invitations()
            ->wherePivot('status', '!=', ReservationParticipant::DECLINED)
            ->detach($participant->id);

        return back()->with('status', 'participant-removed');
    }

    /**
     * A participant who accepted taking themselves off someone else's
     * reservation (an invitation not yet answered is declined instead). The
     * owner can't leave their own booking — that's what cancelling is.
     */
    public function leave(Request $request, Reservation $reservation): RedirectResponse
    {
        $user = $request->user();
        abort_if($reservation->isOwnedBy($user), 403);
        abort_unless($reservation->participants()->whereKey($user->id)->exists(), 403);

        $reservation->participants()->detach($user->id);

        // Not back(): leaving from the group chat page would bounce straight
        // into a 403, since that chat is no longer theirs to view.
        return to_route('reservations.index')->with('status', 'reservation-left');
    }

    /**
     * Finds customers to invite, by display name only. Throttled, and what
     * comes back is just an id and a name: nobody should be able to use this
     * to walk the list of customers or their email addresses.
     */
    public function searchUsers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $matches = User::query()
            ->where('role', 'user')
            ->whereNotNull('email_verified_at')
            ->where('id', '!=', $request->user()->id)
            ->whereRaw('name LIKE ? ESCAPE ?', ['%'.self::escapeLike($validated['q']).'%', '\\'])
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
            ]);

        return response()->json($matches);
    }

    /**
     * Makes LIKE treat what was typed literally: without this "%" or "_"
     * are wildcards, so searching "%%" would match every customer.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
