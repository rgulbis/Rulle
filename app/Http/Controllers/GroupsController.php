<?php

namespace App\Http\Controllers;

use App\Models\ReservationSetting;
use Inertia\Inertia;
use Inertia\Response;

class GroupsController extends Controller
{
    /**
     * The public explanation of group bookings: price and minimum size, open
     * to guests and search engines. Booking itself still happens on the
     * login-only reservations page.
     */
    public function index(): Response
    {
        return Inertia::render('groups/index', [
            'groupBooking' => ReservationSetting::current()->only([
                'price_cents_per_person_per_hour',
                'min_group_size',
            ]),
        ]);
    }
}
