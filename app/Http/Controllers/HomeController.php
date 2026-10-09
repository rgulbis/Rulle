<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\ReservationSetting;
use App\Models\SubscriptionType;
use App\Support\CheckInOccupancy;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * The public landing page: the live camera and headcount up front (same
     * data as the livestream page), plus the passes and group booking info
     * a first-time visitor needs - open to guests, no login wall.
     */
    public function index(): Response
    {
        $settings = ReservationSetting::current();

        return Inertia::render('welcome', [
            'checkedInCount' => CheckInOccupancy::currentlyCheckedInCount(),
            // Time ranges only - who booked a slot isn't anyone else's
            // business, same as the livestream and reservations pages.
            'todaysReservations' => Reservation::where('status', 'active')
                ->whereDate('starts_at', today())
                ->orderBy('starts_at')
                ->get(['starts_at', 'ends_at']),
            'plans' => SubscriptionType::forPublicDisplay(),
            'mostPopularPlanId' => SubscriptionType::mostPopularId(),
            'groupBooking' => $settings->only([
                'price_cents_per_person_per_hour',
                'min_group_size',
            ]),
        ]);
    }
}
