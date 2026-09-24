<?php

namespace App\Http\Controllers\Reservations;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\ReservationSetting;
use App\Models\User;
use App\Support\CheckInOccupancy;
use App\Support\StripeRefunds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Cashier;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

class ReservationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $settings = ReservationSetting::current();

        return Inertia::render('reservations/index', [
            'settings' => $settings->only([
                'price_cents_per_person_per_hour',
                'min_group_size',
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
                ->with('participants:id,name,email')
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
            ],
        ]);

        $startsAt = Carbon::parse($validated['starts_at']);
        $endsAt = $startsAt->copy()->addMinutes($validated['duration_minutes']);

        $startMinutes = $startsAt->hour * 60 + $startsAt->minute;
        $endMinutes = $startMinutes + $validated['duration_minutes'];

        if ($startMinutes < $settings->openingMinutes() || $endMinutes > $settings->closingMinutes()) {
            return back()->withErrors([
                'starts_at' => "The park is only open {$settings->opening_time}–{$settings->closing_time}.",
            ]);
        }

        if (Reservation::overlapping($startsAt, $endsAt)->exists()) {
            return back()->withErrors([
                'starts_at' => 'That time overlaps with an existing reservation.',
            ]);
        }

        $user = $request->user();
        $priceCents = $settings->priceFor($validated['duration_minutes'], $validated['group_size']);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'group_size' => $validated['group_size'],
            'price_cents' => $priceCents,
            'status' => 'pending',
        ]);

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
            $existing = Cashier::stripe()->checkout->sessions->retrieve($reservation->stripe_checkout_session_id);

            if ($existing->status === 'open') {
                return Inertia::location($existing->url);
            }
        }

        return $this->startCheckout($request->user(), $reservation);
    }

    private function startCheckout(User $user, Reservation $reservation): BaseResponse
    {
        $durationMinutes = $reservation->starts_at->diffInMinutes($reservation->ends_at);

        $successUrl = route('reservations.success').'?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = route('reservations.cancel', ['reservation' => $reservation]);

        $session = $user->checkoutCharge(
            $reservation->price_cents,
            'Park reservation ('.$durationMinutes.' min, '.$reservation->group_size.' people)',
            1,
            ['success_url' => $successUrl, 'cancel_url' => $cancelUrl, 'mode' => 'payment'],
        )->asStripeCheckoutSession();

        $reservation->update(['stripe_checkout_session_id' => $session->id]);

        return Inertia::location($session->url);
    }

    public function success(Request $request): RedirectResponse
    {
        $sessionId = $request->query('session_id');
        $status = 'reservation-incomplete';

        if ($sessionId) {
            $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId);

            if ($session->status === 'complete' && $session->payment_status === 'paid') {
                $reservation = Reservation::where('stripe_checkout_session_id', $sessionId)->first();

                if ($reservation && $reservation->status === 'pending') {
                    // Someone else's reservation for an overlapping slot may
                    // have finished paying first while this checkout was
                    // still open. If so, this payment already went through —
                    // refund it and cancel the reservation rather than
                    // creating a second, conflicting active booking.
                    if ($reservation->overlappedByAnotherActiveReservation()) {
                        $reservation->update(['status' => 'cancelled']);

                        if ($session->payment_intent) {
                            Cashier::stripe()->refunds->create(['payment_intent' => $session->payment_intent]);
                        }

                        $status = 'reservation-slot-taken';
                    } else {
                        $reservation->update(['status' => 'active']);
                        $status = 'reservation-complete';
                    }
                } elseif ($reservation && $reservation->status === 'active') {
                    // Revisiting the success URL (e.g. a page reload).
                    $status = 'reservation-complete';
                }
            }
        }

        return redirect()->route('reservations.index')->with('status', $status);
    }

    public function cancel(Request $request, Reservation $reservation): RedirectResponse
    {
        if ($reservation->user_id !== $request->user()->id) {
            return redirect()->route('reservations.index')->with('status', 'reservation-cancelled');
        }

        // Frees up the slot immediately instead of waiting out the pending
        // grace window in Reservation::scopeOverlapping(). Nothing was ever
        // charged for a pending reservation, so there's nothing to refund.
        if ($reservation->status === 'pending') {
            $reservation->update(['status' => 'cancelled']);

            return redirect()->route('reservations.index')->with('status', 'reservation-cancelled');
        }

        // A paid reservation can still be called off if the customer
        // changes their mind, as long as it hasn't started yet. Whether
        // that refunds them depends on how close to the start time it is.
        if ($reservation->status === 'active' && $reservation->starts_at->isFuture()) {
            $settings = ReservationSetting::current();
            $refunded = false;

            if ($settings->isEligibleForCancellationRefund($reservation->starts_at)) {
                $refunded = StripeRefunds::refundCheckoutSession($reservation->stripe_checkout_session_id);
            }

            $reservation->update(['status' => 'cancelled']);

            return redirect()->route('reservations.index')
                ->with('status', $refunded ? 'reservation-cancelled-refunded' : 'reservation-cancelled-no-refund');
        }

        if ($reservation->status === 'active') {
            return redirect()->route('reservations.index')->with('status', 'reservation-cannot-cancel');
        }

        return redirect()->route('reservations.index')->with('status', 'reservation-cancelled');
    }

    public function addParticipant(Request $request, Reservation $reservation): RedirectResponse
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);
        abort_if($reservation->ends_at->isPast(), 422, 'This reservation has already ended.');

        if (! $reservation->hasParticipantCapacity()) {
            return back()->withErrors([
                'user_id' => 'This reservation is already at its paid group size.',
            ]);
        }

        $validated = $request->validate([
            'user_id' => [
                'required',
                Rule::exists('users', 'id')->where('role', 'user'),
                Rule::notIn([$reservation->user_id]),
            ],
        ]);

        if (! $reservation->participants()->whereKey($validated['user_id'])->exists()) {
            $reservation->participants()->attach($validated['user_id']);
        }

        return back()->with('status', 'participant-added');
    }

    public function removeParticipant(Request $request, Reservation $reservation, User $participant): RedirectResponse
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);

        $reservation->participants()->detach($participant->id);

        return back()->with('status', 'participant-removed');
    }

    public function searchUsers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2'],
        ]);

        $matches = User::query()
            ->where('role', 'user')
            ->where('id', '!=', $request->user()->id)
            ->where(function ($query) use ($validated) {
                $query->where('name', 'like', "%{$validated['q']}%")
                    ->orWhere('email', 'like', "%{$validated['q']}%");
            })
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
