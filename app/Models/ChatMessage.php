<?php

namespace App\Models;

use App\Events\ChatMessageDeleted;
use App\Events\ChatMessageSent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $body
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'body'])]
class ChatMessage extends Model
{
    protected static function booted(): void
    {
        // Broadcasting here (rather than in the controller) means every
        // creation/deletion path — the customer route, staff moderation,
        // tinker — announces itself the same way, with nothing to forget.
        static::created(fn (ChatMessage $message) => ChatMessageSent::dispatch($message));
        static::deleted(fn (ChatMessage $message) => ChatMessageDeleted::dispatch($message->id));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
