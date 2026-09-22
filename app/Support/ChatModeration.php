<?php

namespace App\Support;

use App\Models\ChatMessage;
use App\Models\User;

/**
 * Who may moderate whom in the global chat room. This is the single place the
 * hierarchy is defined — the employee routes and the admin's Filament actions
 * both go through it, so the two can't drift apart.
 *
 *   customer  no moderation rights.
 *   employee  deletes, mutes and unmutes customers only. Can pin any global
 *             message (pinning isn't a penalty against anyone).
 *   admin     (via Filament) deletes any global message, and mutes/unmutes
 *             employees and customers. Nobody can mute an admin.
 *
 * Nobody can moderate themselves or someone of equal rank.
 */
class ChatModeration
{
    public static function canMute(User $actor, User $target): bool
    {
        return $actor->outranks($target);
    }

    public static function canDelete(User $actor, ChatMessage $message): bool
    {
        // A message that isn't in the global room is not this feature's to
        // touch, whoever is asking.
        if ($message->reservation_id !== null) {
            return false;
        }

        return $actor->isAdmin() || $actor->outranks($message->user);
    }
}
