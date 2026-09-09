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
 * @property string $status
 * @property int|null $visits_remaining
 * @property Carbon|null $valid_date
 */
#[Fillable(['user_id', 'subscription_type_id', 'stripe_checkout_session_id', 'status', 'visits_remaining', 'valid_date'])]
class Purchase extends Model
{
    protected function casts(): array
    {
        return [
            'visits_remaining' => 'integer',
            'valid_date' => 'date',
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
