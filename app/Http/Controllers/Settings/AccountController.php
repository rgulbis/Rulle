<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\CheckInEvent;
use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Accounts\AccountClosure;
use App\Support\Accounts\AccountClosureBlocked;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;

/**
 * The customer's own copy of their data, and the way to close their account.
 * Closing reuses AccountClosure (the admin panel's path): the person is
 * anonymised and signed out while purchases, reservations and check-ins stay
 * as the park's records.
 */
class AccountController extends Controller
{
    public function edit(Request $request, AccountClosure $closure): Response
    {
        $user = $request->user();

        return Inertia::render('settings/account', [
            'canDelete' => $user->isCustomer(),
            // Customers can only be held back by an upcoming paid reservation.
            'deleteBlocked' => $user->isCustomer() && $closure->blockers($user) !== [],
        ]);
    }

    /**
     * Everything tied to the signed-in user as one JSON file. Secrets (the QR
     * signing key, password hash, Stripe ids) are left out, and so is
     * anything that identifies other people.
     */
    public function export(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = [
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'name' => $user->name,
                'pending_name' => $user->pending_name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'role' => $user->role,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'purchases' => Purchase::with('subscriptionType')
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->get()
                ->map(fn (Purchase $purchase) => [
                    'plan' => $purchase->subscriptionType->name,
                    'price_cents' => $purchase->price_cents,
                    'status' => $purchase->status,
                    'payment_status' => $purchase->payment_status,
                    'refunded_cents' => $purchase->refunded_cents,
                    'visits_remaining' => $purchase->visits_remaining,
                    'valid_date' => $purchase->valid_date?->toDateString(),
                    'created_at' => $purchase->created_at?->toIso8601String(),
                ]),
            'subscriptions' => Subscription::where('user_id', $user->id)
                ->orderBy('id')
                ->get()
                ->map(fn (Subscription $subscription) => [
                    'status' => $subscription->stripe_status,
                    'ends_at' => $subscription->ends_at?->toIso8601String(),
                    'created_at' => $subscription->created_at?->toIso8601String(),
                ]),
            'reservations' => Reservation::involving($user)
                ->orderBy('id')
                ->get()
                ->map(fn (Reservation $reservation) => [
                    'role' => $reservation->user_id === $user->id ? 'owner' : 'participant',
                    'starts_at' => $reservation->starts_at->toIso8601String(),
                    'ends_at' => $reservation->ends_at->toIso8601String(),
                    'group_size' => $reservation->group_size,
                    'price_cents' => $reservation->user_id === $user->id ? $reservation->price_cents : null,
                    'status' => $reservation->status,
                    'payment_status' => $reservation->user_id === $user->id ? $reservation->payment_status : null,
                ]),
            'check_ins' => CheckInEvent::where('user_id', $user->id)
                ->orderBy('id')
                ->get()
                ->map(fn (CheckInEvent $event) => [
                    'type' => $event->checked_in ? 'in' : 'out',
                    'at' => $event->created_at?->toIso8601String(),
                ]),
            'chat_messages' => ChatMessage::where('user_id', $user->id)
                ->orderBy('id')
                ->get()
                ->map(fn (ChatMessage $message) => [
                    'room' => $message->reservation_id === null ? 'global' : 'reservation',
                    'body' => $message->body,
                    'sent_at' => $message->created_at->toIso8601String(),
                ]),
        ];

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="my-data.json"',
            'Cache-Control' => 'no-store',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Staff and admin accounts are closed from the admin panel, where the
     * "last admin" and role rules are enforced.
     */
    public function destroy(Request $request, AccountClosure $closure): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->isCustomer(), 403);

        $request->validate(['password' => ['required', 'string', 'current_password']]);

        try {
            $closure->close($user);
        } catch (AccountClosureBlocked) {
            return back()->withErrors(['password' => __('You have an upcoming paid reservation. Cancel it first.')]);
        } catch (ApiErrorException) {
            return back()->withErrors(['password' => __('Could not cancel your subscription right now. Please try again in a moment.')]);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', __('Your account has been deleted.'));
    }
}
