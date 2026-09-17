<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\User;
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
            ->latest()
            ->limit(100)
            ->get()
            ->reverse()
            ->values();

        return Inertia::render('chat/index', [
            'messages' => $messages,
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

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
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
