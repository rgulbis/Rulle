<?php

namespace App\Support;

use App\Events\ChatSlowModeActivated;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Discord-style slow mode for the global chat room: when the room gets busy
 * it switches itself on for a fixed stretch, during which customers can post
 * once per cooldown. Staff are never throttled, and reservation group chats
 * (small private groups) don't use it at all.
 */
class ChatSlowMode
{
    // This many global messages inside the window turns slow mode on...
    private const TRIGGER_MESSAGES = 10;

    private const TRIGGER_WINDOW_SECONDS = 30;

    // ...for this long (it re-triggers by itself if the room is still busy)...
    private const DURATION_SECONDS = 120;

    // ...and each customer then has to wait this long between messages.
    private const COOLDOWN_SECONDS = 10;

    private const CACHE_KEY = 'chat.slow_mode_until';

    public static function remainingSeconds(): int
    {
        $until = (int) Cache::get(self::CACHE_KEY, 0);

        return max(0, $until - now()->getTimestamp());
    }

    public static function isActive(): bool
    {
        return self::remainingSeconds() > 0;
    }

    /**
     * @return array{remaining_seconds: int, cooldown_seconds: int}
     */
    public static function state(): array
    {
        return [
            'remaining_seconds' => self::remainingSeconds(),
            'cooldown_seconds' => self::COOLDOWN_SECONDS,
        ];
    }

    /**
     * How long this user still has to wait before posting to the global
     * room (0 = they can post now).
     */
    public static function secondsUntilMayPost(User $user): int
    {
        if (! $user->isCustomer() || ! self::isActive()) {
            return 0;
        }

        $last = ChatMessage::whereNull('reservation_id')
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->first();

        if ($last === null) {
            return 0;
        }

        $elapsed = now()->getTimestamp() - $last->created_at->getTimestamp();

        return max(0, self::COOLDOWN_SECONDS - $elapsed);
    }

    /**
     * Called after each new global message: switches slow mode on if the
     * room just got busy enough.
     */
    public static function evaluate(): void
    {
        if (self::isActive()) {
            return;
        }

        $recent = ChatMessage::whereNull('reservation_id')
            ->where('created_at', '>=', now()->subSeconds(self::TRIGGER_WINDOW_SECONDS))
            ->count();

        if ($recent < self::TRIGGER_MESSAGES) {
            return;
        }

        Cache::put(self::CACHE_KEY, now()->getTimestamp() + self::DURATION_SECONDS, self::DURATION_SECONDS + 60);

        ChatSlowModeActivated::dispatch(self::DURATION_SECONDS, self::COOLDOWN_SECONDS);
    }
}
