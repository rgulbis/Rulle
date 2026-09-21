<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\User;
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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if($request->user()->isChatMuted(), 403, 'You are muted from chat.');

        $wait = ChatSlowMode::secondsUntilMayPost($request->user());

        if ($wait > 0) {
            return back()->withErrors([
                'body' => "Slow mode is on — wait {$wait}s before sending another message.",
            ]);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:500'],
        ]);

        ChatMessage::create([
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        return back();
    }

    public function destroy(Request $request, ChatMessage $message): RedirectResponse
    {
        abort_unless($request->user()->isEmployee(), 403);

        $message->delete();

        return back();
    }

    public function pin(Request $request, ChatMessage $message): RedirectResponse
    {
        abort_unless($request->user()->isEmployee(), 403);
        // Reservation group chats are private to their group — nothing to pin.
        abort_unless($message->reservation_id === null, 404);

        $message->pin();

        return back();
    }

    public function unpin(Request $request, ChatMessage $message): RedirectResponse
    {
        abort_unless($request->user()->isEmployee(), 403);
        abort_unless($message->reservation_id === null, 404);

        $message->unpin();

        return back();
    }

    public function mute(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isEmployee(), 403);
        abort_if($user->id === $request->user()->id, 422, 'You cannot mute yourself.');

        $validated = $request->validate([
            'hours' => ['required', 'integer', 'min:1', 'max:8760'],
        ]);

        $user->update(['chat_muted_until' => now()->addHours($validated['hours'])]);

        return back();
    }

    public function unmute(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isEmployee(), 403);

        $user->update(['chat_muted_until' => null]);

        return back();
    }
}
