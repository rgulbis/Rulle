<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int $group_size
 * @property int $price_cents
 * @property string $status
 * @property string|null $stripe_checkout_session_id
 */
#[Fillable(['user_id', 'starts_at', 'ends_at', 'group_size', 'price_cents', 'status', 'stripe_checkout_session_id'])]
class Reservation extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'group_size' => 'integer',
            'price_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Everyone besides the owner who's part of this reservation.
     *
     * @return BelongsToMany<User, $this>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function includesParticipant(User $user): bool
    {
        return $this->user_id === $user->id
            || $this->participants()->whereKey($user->id)->exists();
    }

    /**
     * The owner counts as one of the paid-for group_size, so there's room
     * for at most group_size - 1 additional named participants.
     */
    public function hasParticipantCapacity(): bool
    {
        return $this->participants()->count() < $this->group_size - 1;
    }

    public function isUpcomingOrOngoing(): bool
    {
        return $this->status === 'active' && $this->ends_at->isFuture();
    }

    /**
     * The reservation currently blocking general entry, if any. Used by the
     * scanner to know whether the park is privately reserved right now.
     */
    public static function activeNow(): ?self
    {
        $now = now();

        return static::query()
            ->where('status', 'active')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->first();
    }

    /**
     * A reservation blocks its time slot while it's paid ("active"), or
     * while it's still within a grace period of being created ("pending" —
     * mid-checkout). Without that grace window, someone who starts paying
     * but abandons Stripe Checkout would permanently lock the slot, since
     * nothing else ever flips their reservation to "cancelled".
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOverlapping($query, CarbonInterface $start, CarbonInterface $end)
    {
        return $query
            ->where(function ($query) {
                $query->where('status', 'active')
                    ->orWhere(function ($query) {
                        $query->where('status', 'pending')
                            ->where('created_at', '>=', now()->subMinutes(30));
                    });
            })
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start);
    }
}
