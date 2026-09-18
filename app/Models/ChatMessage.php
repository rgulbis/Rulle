<?php

namespace App\Models;

use App\Events\ChatMessageDeleted;
use App\Events\ChatMessageSent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $reservation_id
 * @property string $body
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'reservation_id', 'body'])]
class ChatMessage extends Model
{
    protected static function booted(): void
    {
        // Broadcasting here (rather than in the controller) means every
        // creation/deletion path — the customer route, staff moderation,
        // tinker — announces itself the same way, with nothing to forget.
        static::created(fn (ChatMessage $message) => ChatMessageSent::dispatch($message));
        static::deleted(fn (ChatMessage $message) => ChatMessageDeleted::dispatch($message->id, $message->reservation_id));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * Shaped explicitly (not the raw models) so nothing beyond what the page
     * actually renders — e.g. the raw user_id column — leaks into the
     * Inertia payload. Matches ChatMessageSent::broadcastWith()'s shape, so
     * the initial load and the live broadcast look identical.
     *
     * @param  Collection<int, self>  $messages
     * @return Collection<int, array{id: int, body: string, created_at: Carbon, user: array{id: int, name: string, role: string}}>
     */
    public static function shapeForClient(Collection $messages): Collection
    {
        return $messages->map(fn (self $message) => [
            'id' => $message->id,
            'body' => $message->body,
            'created_at' => $message->created_at,
            'user' => [
                'id' => $message->user->id,
                'name' => $message->user->name,
                'role' => $message->user->role,
            ],
        ]);
    }
}
