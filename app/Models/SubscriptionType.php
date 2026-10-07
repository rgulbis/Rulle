<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string|null $name_lv
 * @property string|null $description_lv
 * @property int $price_cents
 * @property string $billing_interval
 * @property int|null $visit_limit
 * @property bool $unlimited_entries
 * @property string|null $stripe_product_id
 * @property string|null $stripe_price_id
 * @property bool $active
 * @property string|null $stripe_synced_name
 * @property int|null $stripe_synced_price_cents
 * @property string|null $stripe_synced_interval
 */
#[Fillable(['name', 'description', 'name_lv', 'description_lv', 'price_cents', 'billing_interval', 'visit_limit', 'unlimited_entries', 'active'])]
class SubscriptionType extends Model
{
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'visit_limit' => 'integer',
            'unlimited_entries' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * Stripe won't create a Price below this for a EUR payment.
     */
    public const MIN_PRICE_CENTS = 50;

    /**
     * Talking to Stripe is deliberately not done from a model event: a slow
     * or failing API call must not decide whether an admin's edit is saved.
     * Saving a plan only records what it should look like; syncing it is
     * `PlanStripeSync`, run by the admin pages, the "Sync to Stripe" action
     * and the scheduled `plans:sync-stripe`, and safe to repeat.
     *
     * What is guarded here is what must hold however a plan gets changed.
     */
    protected static function booted(): void
    {
        static::updating(function (SubscriptionType $type) {
            if ($type->isDirty('billing_interval') && $type->hasSales()) {
                throw ValidationException::withMessages([
                    'billing_interval' => 'The billing type can\'t change once this plan has sales or subscribers. Create a new plan instead.',
                ]);
            }
        });

        static::deleting(function (SubscriptionType $type) {
            if ($reason = $type->deletionBlocker()) {
                throw ValidationException::withMessages(['plan' => $reason]);
            }
        });
    }

    /**
     * @return HasMany<Purchase, $this>
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /**
     * Customers who hold, or ever held, a Stripe subscription to this plan.
     * Subscriptions are matched to a plan by its Stripe product, which
     * (unlike the price) survives repricing.
     */
    public function subscriberCount(): int
    {
        if (! $this->stripe_product_id) {
            return 0;
        }

        return DB::table('subscription_items')
            ->where('stripe_product', $this->stripe_product_id)
            ->distinct()
            ->count('subscription_id');
    }

    /**
     * Whether anyone has paid for this plan, as opposed to a checkout that
     * was started and never finished.
     */
    public function hasSales(): bool
    {
        return $this->purchases()->where('payment_status', '!=', 'unpaid')->exists()
            || $this->subscriberCount() > 0;
    }

    /**
     * Why this plan can't be deleted, or null if it can. Every purchase row
     * counts (an unfinished checkout still points at the plan, and the
     * database refuses to orphan it); deactivating hides a plan from the
     * shop without losing any of that history.
     */
    public function deletionBlocker(): ?string
    {
        $purchases = $this->purchases()->count();
        $subscribers = $this->subscriberCount();

        if ($purchases === 0 && $subscribers === 0) {
            return null;
        }

        $parts = array_filter([
            $purchases > 0 ? $purchases.' '.Str::plural('purchase', $purchases) : null,
            $subscribers > 0 ? $subscribers.' '.Str::plural('subscriber', $subscribers) : null,
        ]);

        return 'This plan has '.implode(' and ', $parts).' and can\'t be deleted. Deactivate it instead to stop selling it.';
    }

    /**
     * Stripe is missing something this plan should have: its Product or
     * Price, or a Price for the current amount/interval, or the current name.
     */
    public function needsStripeSync(): bool
    {
        return ! $this->stripe_product_id
            || ! $this->stripe_price_id
            || $this->stripe_synced_name !== $this->name
            || $this->stripe_synced_price_cents !== $this->price_cents
            || $this->stripe_synced_interval !== $this->billing_interval;
    }

    /**
     * The plan that has actually sold the most — what the "most picked"
     * badge on the pass cards points at. One-time passes count completed
     * purchases (not unfinished checkouts or refunds); subscriptions count
     * by Stripe product, so a plan keeps its sales across price changes.
     * No sales yet, or a tie at the top, means no badge at all.
     */
    public static function mostPopularId(): ?int
    {
        $sales = DB::table('purchases')
            ->whereIn('status', ['active', 'used_up'])
            ->selectRaw('subscription_type_id as type_id, count(*) as total')
            ->groupBy('subscription_type_id')
            ->pluck('total', 'type_id')
            ->map(fn ($total) => (int) $total);

        $subscriptionSales = DB::table('subscription_items')
            ->join('subscriptions', 'subscriptions.id', '=', 'subscription_items.subscription_id')
            ->join('subscription_types', 'subscription_types.stripe_product_id', '=', 'subscription_items.stripe_product')
            ->whereNotIn('subscriptions.stripe_status', ['incomplete', 'incomplete_expired'])
            ->selectRaw('subscription_types.id as type_id, count(*) as total')
            ->groupBy('subscription_types.id')
            ->pluck('total', 'type_id');

        foreach ($subscriptionSales as $typeId => $total) {
            $sales[$typeId] = ($sales[$typeId] ?? 0) + (int) $total;
        }

        $sales = $sales->sortDesc();
        $top = $sales->first();

        if (! $top || $sales->filter(fn ($total) => $total === $top)->count() > 1) {
            return null;
        }

        return (int) $sales->keys()->first();
    }

    public function isRecurring(): bool
    {
        return in_array($this->billing_interval, ['month', 'year'], true);
    }
}
