<?php

namespace App\Support;

use App\Models\ChatMessage;
use App\Models\Reservation;
use App\Models\User;

/**
 * Who may moderate what in a reservation's own group chat. Unlike the
 * global room ({@see ChatModeration}), everyone here is a customer, so
 * moderation isn't rank-based - it's ownership-based: the person who booked
 * the reservation moderates their own chat, and nobody moderates themselves.
 */
class ReservationChatModeration
{
    public static function canModerate(User $actor, Reservation $reservation): bool
    {
        return $reservation->isOwnedBy($actor);
    }

    public static function canMute(User $actor, User $target, Reservation $reservation): bool
    {
        return self::canModerate($actor, $reservation)
            && $actor->id !== $target->id
            && $reservation->participants()->whereKey($target->id)->exists();
    }

    public static function canDelete(User $actor, ChatMessage $message, Reservation $reservation): bool
    {
        return self::canModerate($actor, $reservation)
            && $message->reservation_id === $reservation->id
            && $message->user_id !== $actor->id;
    }
}
