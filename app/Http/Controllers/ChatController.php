<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\Reservation;
use App\Models\User;
use App\Rules\NoInappropriateContent;
use App\Support\ChatModeration;
use App\Support\ChatSlowMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $messages = ChatMessage::with(['user:id,name,role', 'replyTo.user:id,name,role'])
            ->whereNull('reservation_id')
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
            ->whereNull('reservation_id')
            ->whereNotNull('pinned_at')
            ->orderByDesc('pinned_at')
            ->get();

        return Inertia::render('chat/index', [
            'messages' => ChatMessage::shapeForClient($messages),
            'pinned' => ChatMessage::shapeForClient($pinned),
            'slowMode' => ChatSlowMode::state(),
            // Admins moderate from the Filament admin panel instead — this
            // page's inline moderation is for employees, who can't get into
            // Filament at all (User::canAccessPanel() is admin-only).
            'canModerate' => $user->isEmployee(),
            'muted' => $user->isChatMuted(),
            'mutedUntil' => $user->chat_muted_until,
            'chatGroups' => Reservation::chatGroupsFor($user),
            // Only for employees, and only customers — the one case this
            // page's moderation can actually unmute (ChatModeration::canMute
            // is rank-based: an employee can never touch a peer or admin's
            // mute regardless). Otherwise an unmuted account with no message
            // in the visible window had no way to be found at all.
            'mutedUsers' => $user->isEmployee()
                ? User::where('role', 'user')
                    ->where('chat_muted_until', '>', now())
                    ->orderBy('chat_muted_until')
                    ->get(['id', 'name', 'chat_muted_until'])
                : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // A plain abort() here would render Laravel's own error page rather
        // than something Inertia can show inline — the composer is already
        // hidden client-side while muted, so this only fires if a mute
        // landed after the page loaded (no live push forces the composer
        // to hide mid-session); same shape of response as slow mode below.
        if ($request->user()->isChatMuted()) {
            return back()->withErrors([
                'body' => __('You are muted from chat.'),
            ]);
        }

        $wait = ChatSlowMode::secondsUntilMayPost($request->user());

        if ($wait > 0) {
            return back()->withErrors([
                'body' => __('Slow mode is on — wait :secondss before sending another message.', ['seconds' => $wait]),
                // The bare number too, so the chat can count it down live
                // instead of showing a fixed "wait 8 s" that goes stale.
                'slow_mode_wait' => (string) $wait,
            ]);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:500', new NoInappropriateContent],
            // Scoped to this same room — a reply can't point at a message
            // from someone's private reservation chat, or vice versa.
            'reply_to_message_id' => [
                'nullable',
                Rule::exists('chat_messages', 'id')->whereNull('reservation_id'),
            ],
        ]);

        ChatMessage::create([
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
            'reply_to_message_id' => $validated['reply_to_message_id'] ?? null,
        ]);

        return back();
    }

    public function destroy(Request $request, ChatMessage $globalMessage): RedirectResponse
    {
        abort_unless($request->user()->isEmployee(), 403);
        abort_unless(ChatModeration::canDelete($request->user(), $globalMessage), 403);

        $globalMessage->delete();

        return back();
    }

    public function pin(Request $request, ChatMessage $globalMessage): RedirectResponse
    {
        abort_unless($request->user()->isEmployee(), 403);

        $globalMessage->pin();

        return back();
    }

    public function unpin(Request $request, ChatMessage $globalMessage): RedirectResponse
    {
        abort_unless($request->user()->isEmployee(), 403);

        $globalMessage->unpin();

        return back();
    }

    public function mute(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isEmployee(), 403);
        abort_if($user->id === $request->user()->id, 422, 'You cannot mute yourself.');
        abort_unless(ChatModeration::canMute($request->user(), $user), 403);

        $validated = $request->validate([
            'hours' => ['required', 'integer', 'min:1', 'max:8760'],
        ]);

        $user->update(['chat_muted_until' => now()->addHours($validated['hours'])]);

        return back();
    }

    public function unmute(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isEmployee(), 403);
        abort_unless(ChatModeration::canMute($request->user(), $user), 403);

        $user->update(['chat_muted_until' => null]);

        return back();
    }
}
