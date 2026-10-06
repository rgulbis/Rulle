<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $subscription_type_id
 * @property string $stripe_checkout_session_id
 * @property int|null $price_cents
 * @property string $status
 * @property int|null $visits_remaining
 * @property Carbon|null $valid_date
 * @property string $payment_status
 * @property string|null $stripe_payment_intent_id
 * @property Carbon|null $paid_at
 * @property int $refunded_cents
 * @property Carbon|null $refunded_at
 */
#[Fillable(['user_id', 'subscription_type_id', 'stripe_checkout_session_id', 'price_cents', 'status', 'visits_remaining', 'valid_date', 'payment_status', 'stripe_payment_intent_id', 'paid_at', 'refunded_cents', 'refunded_at'])]
class Purchase extends Model
{
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'visits_remaining' => 'integer',
            'valid_date' => 'date',
            'paid_at' => 'datetime',
            'refunded_cents' => 'integer',
            'refunded_at' => 'datetime',
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
     * @return BelongsTo<SubscriptionType, $this>
     */
    public function subscriptionType(): BelongsTo
    {
        return $this->belongsTo(SubscriptionType::class);
    }

    /**
     * Whether this purchase currently grants entry. Unlimited-entry plans
     * (e.g. a day pass) are valid all day on their `valid_date`; other
     * one-time plans are valid until their visit count runs out.
     */
    public function isCurrentlyUsable(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->subscriptionType->unlimited_entries) {
            return $this->valid_date !== null && $this->valid_date->isToday();
        }

        return $this->visits_remaining !== null && $this->visits_remaining > 0;
    }
}
