<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $subscription_type_id
 * @property string $stripe_checkout_session_id
 * @property string $status
 * @property int|null $visits_remaining
 */
#[Fillable(['user_id', 'subscription_type_id', 'stripe_checkout_session_id', 'status', 'visits_remaining'])]
class Purchase extends Model
{
    protected function casts(): array
    {
        return [
            'visits_remaining' => 'integer',
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
}
