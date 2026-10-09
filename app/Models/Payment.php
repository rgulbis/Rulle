<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A read-only row from the `payments` database view (see its migration),
 * which unions one-time passes, reservations, and Stripe subscriptions into
 * a single ledger. Nothing ever saves one of these - there's no table to
 * write to, only the view.
 *
 * A subscription row's amount is what the subscriber is billed
 * (`subscriptions.price_cents`, recorded from Stripe's subscription events),
 * not the plan's current price - repricing a plan doesn't rewrite history.
 *
 * @property string $id
 * @property string $type 'purchase' | 'reservation' | 'subscription'
 * @property int $user_id
 * @property string $description
 * @property int $amount_cents
 * @property string $status
 * @property string $payment_status 'unpaid' | 'paid' | 'refunded' | 'partially_refunded' | 'refund_failed'
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
        return $this->belongsTo(User::class)->withTrashed();
    }
}
