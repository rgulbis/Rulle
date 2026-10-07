<?php

namespace App\Models;

use App\Models\Concerns\HasPaymentState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int $group_size
 * @property int $price_cents
 * @property string $status
 * @property string $payment_status
 * @property int|null $refund_requested_cents
 * @property int $refunded_cents
 * @property Carbon|null $refunded_at
 * @property string|null $stripe_checkout_session_id
 */
#[Fillable(['user_id', 'starts_at', 'ends_at', 'group_size', 'price_cents', 'status', 'payment_status', 'refund_requested_cents', 'refunded_cents', 'refunded_at', 'stripe_checkout_session_id'])]
class Reservation extends Model
{
    use HasPaymentState;

    /**
     * How long a finished reservation's group chat stays readable.
     */
    public const CHAT_READABLE_DAYS_AFTER_END = 7;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'group_size' => 'integer',
            'price_cents' => 'integer',
            'refund_requested_cents' => 'integer',
            'refunded_cents' => 'integer',
            'refunded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Every invitation to this reservation, whatever became of it: someone
     * who has not answered yet, who accepted, or who declined. Only
     * {@see self::participants()} are actually part of the reservation.
     *
     * @return BelongsToMany<User, $this, ReservationParticipant>
     */
    public function invitations(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(ReservationParticipant::class)
            ->withTimestamps()
            ->withPivot('status', 'responded_at', 'chat_muted_until');
    }

    /**
     * The same invitations as {@see self::invitations()}, as rows rather than
     * as users: for listing who was invited and what became of it.
     *
     * @return HasMany<ReservationParticipant, $this>
     */
    public function invitationRows(): HasMany
    {
        return $this->hasMany(ReservationParticipant::class);
    }

    /**
     * Everyone besides the owner who accepted an invitation. An invited or
     * declined person is not one of them: they take no seat of the paid
     * group size and have no access to the group chat.
     *
     * @return BelongsToMany<User, $this, ReservationParticipant>
     */
    public function participants(): BelongsToMany
    {
        return $this->invitations()->wherePivot('status', ReservationParticipant::ACCEPTED);
    }

    /**
     * Reservations a user takes part in: they own it, or accepted an
     * invitation to it.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInvolving(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user) {
            $query->where('user_id', $user->id)
                ->orWhereHas('participants', fn ($q) => $q->whereKey($user->id));
        });
    }

    public function includesParticipant(User $user): bool
    {
        return $this->user_id === $user->id
            || $this->participants()->whereKey($user->id)->exists();
    }

    /**
     * The invitation a user holds for this reservation, answered or not.
     */
    public function invitationFor(User $user): ?ReservationParticipant
    {
        /** @var ReservationParticipant|null $pivot */
        $pivot = $this->invitations()->whereKey($user->id)->first()?->pivot;

        return $pivot;
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
        return $this->participants()->count() < $this->group_size - 1;
    }

    public function isUpcomingOrOngoing(): bool
    {
        return $this->status === 'active' && $this->ends_at->isFuture();
    }

    /**
     * Whether messages can be posted (and the chat moderated): only while
     * the reservation is paid for and has not ended. Before it starts is
     * fine — that is when a group arranges things.
     */
    public function chatIsWritable(): bool
    {
        return $this->isUpcomingOrOngoing();
    }

    /**
     * Whether the chat can be opened at all: a paid reservation, until a
     * week after it ended, read-only once it is over. A pending or
     * cancelled reservation has no chat. The websocket channel uses this
     * same rule.
     */
    public function chatIsReadable(): bool
    {
        return $this->status === 'active'
            && $this->ends_at->gt(now()->subDays(self::CHAT_READABLE_DAYS_AFTER_END));
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
     * The reservation group chats a user can open — ones they own or accepted
     * an invitation to, that are paid for and not over for more than a week —
     * for the chat sidebar.
     *
     * @return Collection<int, self>
     */
    public static function chatGroupsFor(User $user): Collection
    {
        return self::query()
            ->involving($user)
            ->where('status', 'active')
            ->where('ends_at', '>', now()->subDays(self::CHAT_READABLE_DAYS_AFTER_END))
            ->orderBy('starts_at')
            ->get(['id', 'starts_at', 'ends_at']);
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
