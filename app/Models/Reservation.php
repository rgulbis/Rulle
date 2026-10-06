<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
 * @property string $payment_status
 * @property string|null $stripe_payment_intent_id
 * @property Carbon|null $paid_at
 * @property int $refunded_cents
 * @property Carbon|null $refunded_at
 * @property Carbon|null $cancelled_at
 */
#[Fillable(['user_id', 'starts_at', 'ends_at', 'group_size', 'price_cents', 'status', 'stripe_checkout_session_id', 'payment_status', 'stripe_payment_intent_id', 'paid_at', 'refunded_cents', 'refunded_at', 'cancelled_at'])]
class Reservation extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'group_size' => 'integer',
            'price_cents' => 'integer',
            'paid_at' => 'datetime',
            'refunded_cents' => 'integer',
            'refunded_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        // Closed accounts are soft-deleted; their history keeps pointing at them.
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Everyone besides the owner who has *accepted* being part of this
     * reservation. Group chat, entry and every access check go through this;
     * a pending invitation grants nothing.
     *
     * @return BelongsToMany<User, $this, ReservationParticipant>
     */
    public function participants(): BelongsToMany
    {
        return $this->allParticipants()->wherePivot('status', 'accepted');
    }

    /**
     * People invited who haven't answered yet.
     *
     * @return BelongsToMany<User, $this, ReservationParticipant>
     */
    public function invitedUsers(): BelongsToMany
    {
        return $this->allParticipants()->wherePivot('status', 'invited');
    }

    /**
     * Accepted and invited alike — used where a seat is being counted or an
     * invitation created, withdrawn or answered.
     *
     * @return BelongsToMany<User, $this, ReservationParticipant>
     */
    public function allParticipants(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(ReservationParticipant::class)
            ->withTimestamps()
            ->withPivot('chat_muted_until', 'status', 'responded_at');
    }

    public function includesParticipant(User $user): bool
    {
        return $this->user_id === $user->id
            || $this->participants()->whereKey($user->id)->exists();
    }

    /**
     * Null for the owner (never a participant row, so never mutable here)
     * and for anyone with no active mute.
     */
    public function participantMutedUntil(User $user): ?CarbonInterface
    {
        /** @var ReservationParticipant|null $pivot */
        $pivot = $this->participants()->whereKey($user->id)->first()?->pivot;

        return $pivot?->chat_muted_until;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * The owner counts as one of the paid-for group_size, so there's room
     * for at most group_size - 1 additional named participants.
     */
    public function hasParticipantCapacity(): bool
    {
        // Pending invitations hold a seat too.
        return $this->allParticipants()->count() < $this->group_size - 1;
    }

    /**
     * Whether the group chat can be opened: only for a paid, live
     * reservation, and only until it has been over for a week (the same
     * window the chat sidebar lists it for). Cancelled or pending
     * reservations have no chat.
     */
    public function chatIsReadable(): bool
    {
        return $this->status === 'active' && $this->ends_at->gt(now()->subDays(7));
    }

    /**
     * Posting stops shortly after the session ends.
     */
    public function chatIsWritable(): bool
    {
        return $this->status === 'active' && $this->ends_at->gt(now()->subHours(2));
    }

    public function isUpcomingOrOngoing(): bool
    {
        return $this->status === 'active' && $this->ends_at->isFuture();
    }

    /**
     * True if some other reservation already claimed (paid for) an
     * overlapping slot. Checked right when a payment completes, since two
     * people can both have a pending reservation for the same overlapping
     * time — whoever's Stripe Checkout finishes first wins the slot, and
     * this is how the other one's payment gets caught and refunded instead
     * of silently creating a second, conflicting active reservation.
     */
    public function overlappedByAnotherActiveReservation(): bool
    {
        return static::query()
            ->where('status', 'active')
            ->where('id', '!=', $this->id)
            ->where('starts_at', '<', $this->ends_at)
            ->where('ends_at', '>', $this->starts_at)
            ->exists();
    }

    /**
     * The reservation currently blocking general entry, if any. Used by the
     * scanner to know whether the park is privately reserved right now.
     */
    /**
     * The reservation group chats a user can open — ones they own or were
     * added to, not cancelled, and not long over — for the chat sidebar.
     *
     * @return Collection<int, self>
     */
    public static function chatGroupsFor(User $user): Collection
    {
        return self::query()
            ->where(function (Builder $query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhereHas('participants', fn ($q) => $q->whereKey($user->id));
            })
            ->where('status', 'active')
            ->where('ends_at', '>', now()->subDays(7))
            ->orderBy('starts_at')
            ->get(['id', 'starts_at', 'ends_at']);
    }

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
