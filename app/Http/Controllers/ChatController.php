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
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $messages = ChatMessage::with('user:id,name,role')
            ->whereNull('reservation_id')
            ->latest()
            ->limit(100)
            ->get()
            ->reverse()
            ->values();

        $pinned = ChatMessage::with('user:id,name,role')
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
        ]);

        ChatMessage::create([
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
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
