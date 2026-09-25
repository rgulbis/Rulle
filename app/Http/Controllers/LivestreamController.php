<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Support\CheckInOccupancy;
use Inertia\Inertia;
use Inertia\Response;

class LivestreamController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('livestream/index', [
            'checkedInCount' => CheckInOccupancy::currentlyCheckedInCount(),
            // Time ranges only — same as the reservations page, who booked
            // a slot isn't anyone else's business.
            'todaysReservations' => Reservation::where('status', 'active')
                ->whereDate('starts_at', today())
                ->orderBy('starts_at')
                ->get(['starts_at', 'ends_at']),
        ]);
    }
}
