<?php

namespace App\Http\Controllers\Reservations;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Reservation;
use App\Models\User;
use App\Rules\NoInappropriateContent;
use App\Support\ReservationChatModeration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReservationChatController extends Controller
{
    public function show(Request $request, Reservation $reservation): Response
    {
        $user = $request->user();
        abort_unless($reservation->includesParticipant($user), 403);

        $messages = ChatMessage::with('user:id,name,role')
            ->where('reservation_id', $reservation->id)
            ->latest()
            ->limit(100)
            ->get()
            ->reverse()
            ->values();

        $pinned = ChatMessage::with('user:id,name,role')
            ->where('reservation_id', $reservation->id)
            ->whereNotNull('pinned_at')
            ->orderByDesc('pinned_at')
            ->get();

        // The owner is never a participant row, so there's nothing to look
        // up for them — they moderate, they aren't moderated.
        $mutedUntil = $reservation->isOwnedBy($user)
            ? null
            : $reservation->participants()->whereKey($user->id)->first()?->pivot->chat_muted_until;

        return Inertia::render('reservations/chat', [
            'reservation' => $reservation->only(['id', 'starts_at', 'ends_at']),
            'messages' => ChatMessage::shapeForClient($messages),
            'pinned' => ChatMessage::shapeForClient($pinned),
            'chatGroups' => Reservation::chatGroupsFor($user),
            'canModerate' => $reservation->isOwnedBy($user),
            'muted' => $mutedUntil !== null && $mutedUntil->isFuture(),
            'mutedUntil' => $mutedUntil,
        ]);
    }

    public function store(Request $request, Reservation $reservation): RedirectResponse
    {
        $user = $request->user();
        abort_unless($reservation->includesParticipant($user), 403);

        if (! $reservation->isOwnedBy($user)) {
            $mutedUntil = $reservation->participants()->whereKey($user->id)->first()?->pivot->chat_muted_until;

            if ($mutedUntil !== null && $mutedUntil->isFuture()) {
                return back()->withErrors([
                    'body' => __('You are muted from this group chat.'),
                ]);
            }
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:500', new NoInappropriateContent],
        ]);

        ChatMessage::create([
            'user_id' => $user->id,
            'reservation_id' => $reservation->id,
            'body' => $validated['body'],
        ]);

        return back();
    }

    public function destroy(Request $request, Reservation $reservation, ChatMessage $message): RedirectResponse
    {
        abort_unless($message->reservation_id === $reservation->id, 404);
        abort_unless(ReservationChatModeration::canDelete($request->user(), $message, $reservation), 403);

        $message->delete();

        return back();
    }

    public function pin(Request $request, Reservation $reservation, ChatMessage $message): RedirectResponse
    {
        abort_unless($message->reservation_id === $reservation->id, 404);
        abort_unless(ReservationChatModeration::canModerate($request->user(), $reservation), 403);

        $message->pin();

        return back();
    }

    public function unpin(Request $request, Reservation $reservation, ChatMessage $message): RedirectResponse
    {
        abort_unless($message->reservation_id === $reservation->id, 404);
        abort_unless(ReservationChatModeration::canModerate($request->user(), $reservation), 403);

        $message->unpin();

        return back();
    }

    public function mute(Request $request, Reservation $reservation, User $user): RedirectResponse
    {
        abort_unless(ReservationChatModeration::canMute($request->user(), $user, $reservation), 403);

        $validated = $request->validate([
            'hours' => ['required', 'integer', 'min:1', 'max:8760'],
        ]);

        $reservation->participants()->updateExistingPivot($user->id, [
            'chat_muted_until' => now()->addHours($validated['hours']),
        ]);

        return back();
    }

    public function unmute(Request $request, Reservation $reservation, User $user): RedirectResponse
    {
        abort_unless(ReservationChatModeration::canMute($request->user(), $user, $reservation), 403);

        $reservation->participants()->updateExistingPivot($user->id, [
            'chat_muted_until' => null,
        ]);

        return back();
    }
}
