<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\SubscriptionType;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('dashboard', [
            'status' => $request->session()->get('status'),
            'pass' => $this->currentPass($user),
            'nextReservation' => $this->nextReservation($user),
        ]);
    }

    /**
     * What the customer currently has access through — a recurring
     * subscription takes precedence over a one-time purchase, matching the
     * order the scanner checks them in.
     *
     * @return array<string, mixed>|null
     */
    protected function currentPass(User $user): ?array
    {
        if ($user->subscribed('default')) {
            $subscription = $user->subscription('default');
            // Matched on the price they're actually paying; after a price
            // change that's an older price, so the plan name can be unknown.
            $type = SubscriptionType::where('stripe_price_id', $subscription->stripe_price)->first();

            return [
                'kind' => 'subscription',
                'name' => $type?->name,
                'name_lv' => $type?->name_lv,
                'billing_interval' => $type?->billing_interval,
                'canceled' => $subscription->canceled(),
                'ends_at' => $subscription->ends_at,
            ];
        }

        $purchase = $user->activeOneTimePurchase();

        if (! $purchase) {
            return null;
        }

        return [
            'kind' => 'purchase',
            'name' => $purchase->subscriptionType->name,
            'name_lv' => $purchase->subscriptionType->name_lv,
            'unlimited_entries' => $purchase->subscriptionType->unlimited_entries,
            'visits_remaining' => $purchase->visits_remaining,
        ];
    }

    /**
     * The soonest paid reservation the user is part of, as owner or added
     * participant — only its time and size, same shape the rest of the app
     * shows a participant.
     *
     * @return array<string, mixed>|null
     */
    protected function nextReservation(User $user): ?array
    {
        $reservation = Reservation::where(function ($query) use ($user) {
            $query->where('user_id', $user->id)
                ->orWhereHas('participants', fn ($q) => $q->whereKey($user->id));
        })
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->first(['id', 'starts_at', 'ends_at', 'group_size']);

        return $reservation?->only(['id', 'starts_at', 'ends_at', 'group_size']);
    }
}
