<?php

namespace App\Models;

use App\Events\ChatMessageDeleted;
use App\Events\ChatMessagePinChanged;
use App\Events\ChatMessageSent;
use App\Support\ChatSlowMode;
use Carbon\CarbonInterface;
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
 * @property CarbonInterface|null $pinned_at
 * @property Carbon $created_at
 *
 * @phpstan-type ClientMessage array{id: int, body: string, created_at: Carbon, pinned: bool, user: array{id: int, name: string, role: string}}
 */
#[Fillable(['user_id', 'reservation_id', 'body'])]
class ChatMessage extends Model
{
    protected function casts(): array
    {
        return [
            'pinned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Broadcasting here (rather than in the controller) means every
        // creation/deletion path — the customer route, staff moderation,
        // tinker — announces itself the same way, with nothing to forget.
        static::created(function (ChatMessage $message) {
            ChatMessageSent::dispatch($message);

            if ($message->reservation_id === null) {
                ChatSlowMode::evaluate();
            }
        });
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

    public function pin(): void
    {
        $this->pinned_at = now();
        $this->save();

        ChatMessagePinChanged::dispatch($this);
    }

    public function unpin(): void
    {
        $this->pinned_at = null;
        $this->save();

        ChatMessagePinChanged::dispatch($this);
    }

    /**
     * Shaped explicitly (not the raw model) so nothing beyond what the page
     * actually renders — e.g. the raw user_id column — leaks into the
     * Inertia payload. Used for the initial page load and every broadcast,
     * so they always look identical.
     *
     * @return ClientMessage
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'created_at' => $this->created_at,
            'pinned' => $this->pinned_at !== null,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'role' => $this->user->role,
            ],
        ];
    }

    /**
     * @param  Collection<int, self>  $messages
     * @return Collection<int, ClientMessage>
     */
    public static function shapeForClient(Collection $messages): Collection
    {
        return $messages->map(fn (self $message) => $message->toClientArray());
    }
}
