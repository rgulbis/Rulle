<?php

namespace App\Http\Controllers\Reservations;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Reservation;
use App\Rules\NoInappropriateContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReservationChatController extends Controller
{
    public function show(Request $request, Reservation $reservation): Response
    {
        abort_unless($reservation->includesParticipant($request->user()), 403);

        $messages = ChatMessage::with('user:id,name,role')
            ->where('reservation_id', $reservation->id)
            ->latest()
            ->limit(100)
            ->get()
            ->reverse()
            ->values();

        return Inertia::render('reservations/chat', [
            'reservation' => $reservation->only(['id', 'starts_at', 'ends_at']),
            'messages' => ChatMessage::shapeForClient($messages),
            'chatGroups' => Reservation::chatGroupsFor($request->user()),
        ]);
    }

    public function store(Request $request, Reservation $reservation): RedirectResponse
    {
        abort_unless($reservation->includesParticipant($request->user()), 403);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:500', new NoInappropriateContent],
        ]);

        ChatMessage::create([
            'user_id' => $request->user()->id,
            'reservation_id' => $reservation->id,
            'body' => $validated['body'],
        ]);

        return back();
    }
}
