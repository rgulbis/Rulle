<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A read-only row from the `payments` database view (see its migration),
 * which unions one-time passes, reservations, and Stripe subscriptions into
 * a single ledger. Nothing ever saves one of these — there's no table to
 * write to, only the view.
 *
 * A subscription row's amount is the price recorded when Stripe reported
 * that subscription (`subscriptions.price_cents`), so a later change to the
 * plan's price doesn't rewrite history; only subscriptions that predate that
 * column fall back to the plan's current price.
 *
 * @property string $id
 * @property string $type 'purchase' | 'reservation' | 'subscription'
 * @property int $user_id
 * @property string $description
 * @property int $amount_cents
 * @property string $status lifecycle status of the item
 * @property string $payment_status unpaid | paid | refund_pending | refunded | refund_failed
 * @property int $refunded_cents
 * @property Carbon $created_at
 */
class Payment extends Model
{
    protected $table = 'payments';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'refunded_cents' => 'integer',
            'created_at' => 'datetime',
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
}
