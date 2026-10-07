<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * The reservation_user pivot row: one person's invitation to a reservation
 * and what became of it (invited, accepted, declined). Given its own class
 * so chat_muted_until and responded_at cast to Carbon — plain ->withPivot()
 * leaves pivot attributes as raw strings, and there's no fluent way to cast a
 * pivot column from the relationship definition itself.
 *
 * @property string $status
 * @property Carbon|null $responded_at
 * @property Carbon|null $chat_muted_until
 */
class ReservationParticipant extends Pivot
{
    public const INVITED = 'invited';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    protected $table = 'reservation_user';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
            'chat_muted_until' => 'datetime',
        ];
    }
}
