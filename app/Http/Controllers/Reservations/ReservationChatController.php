<?php

namespace App\Http\Controllers\Reservations;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Reservation;
use App\Models\ReservationParticipant;
use App\Models\User;
use App\Rules\NoInappropriateContent;
use App\Support\ReservationChatModeration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ReservationChatController extends Controller
{
    public function show(Request $request, Reservation $reservation): Response
    {
        $user = $request->user();
        abort_unless($reservation->includesParticipant($user) && $reservation->chatIsReadable(), 403);

        $messages = ChatMessage::with(['user:id,name,role', 'replyTo.user:id,name,role'])
            ->where('reservation_id', $reservation->id)
            // latest('id'), not plain latest(): SQLite only stores
            // created_at to the second, so two messages sent in the same
            // second (a quick reply, especially) tie on created_at and sort
            // unpredictably — id is already strictly insertion-ordered and
            // never ties.
            ->latest('id')
            ->limit(100)
            ->get()
            ->reverse()
            ->values();

        $pinned = ChatMessage::with(['user:id,name,role', 'replyTo.user:id,name,role'])
            ->where('reservation_id', $reservation->id)
            ->whereNotNull('pinned_at')
            ->orderByDesc('pinned_at')
            ->get();

        $mutedUntil = $reservation->participantMutedUntil($user);
        $isOwner = $reservation->isOwnedBy($user);

        return Inertia::render('reservations/chat', [
            'reservation' => $reservation->only(['id', 'starts_at', 'ends_at']),
            'messages' => ChatMessage::shapeForClient($messages),
            'pinned' => ChatMessage::shapeForClient($pinned),
            'chatGroups' => Reservation::chatGroupsFor($user),
            'canModerate' => $isOwner,
            'muted' => $mutedUntil !== null && $mutedUntil->isFuture(),
            'mutedUntil' => $mutedUntil,
            // Only for the owner — otherwise a participant muted with no
            // message in the visible window had no way to be found at all.
            'mutedParticipants' => $isOwner
                ? ReservationParticipant::with('user:id,name')
                    ->where('reservation_id', $reservation->id)
                    ->where('chat_muted_until', '>', now())
                    ->get()
                    ->map(fn (ReservationParticipant $row) => [
                        'id' => $row->user_id,
                        'name' => $row->user->name,
                        'chat_muted_until' => $row->chat_muted_until,
                    ])
                : [],
        ]);
    }

    public function store(Request $request, Reservation $reservation): RedirectResponse
    {
        $user = $request->user();
        abort_unless($reservation->includesParticipant($user) && $reservation->chatIsReadable(), 403);

        if (! $reservation->chatIsWritable()) {
            return back()->withErrors([
                'body' => __('This group chat is closed.'),
            ]);
        }

        if (! $reservation->isOwnedBy($user)) {
            $mutedUntil = $reservation->participantMutedUntil($user);

            if ($mutedUntil !== null && $mutedUntil->isFuture()) {
                return back()->withErrors([
                    'body' => __('You are muted from this group chat.'),
                ]);
            }
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:500', new NoInappropriateContent],
            // Scoped to this same reservation's room — a reply can't point
            // at a message from the global chat or a different reservation.
            'reply_to_message_id' => [
                'nullable',
                Rule::exists('chat_messages', 'id')->where('reservation_id', $reservation->id),
            ],
        ]);

        ChatMessage::create([
            'user_id' => $user->id,
            'reservation_id' => $reservation->id,
            'body' => $validated['body'],
            'reply_to_message_id' => $validated['reply_to_message_id'] ?? null,
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
