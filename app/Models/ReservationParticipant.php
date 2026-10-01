<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * The reservation_user pivot row, given its own class only so
 * chat_muted_until casts to Carbon — plain ->withPivot() leaves pivot
 * attributes as raw strings, and there's no fluent way to cast a pivot
 * column from the relationship definition itself.
 *
 * @property Carbon|null $chat_muted_until
 */
class ReservationParticipant extends Pivot
{
    protected $table = 'reservation_user';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'chat_muted_until' => 'datetime',
        ];
    }
}
