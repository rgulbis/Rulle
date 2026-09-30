<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Cashier;

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

    protected static function booted(): void
    {
        static::saved(function (SubscriptionType $type) {
            if (! $type->stripe_price_id || $type->wasChanged(['name', 'price_cents', 'billing_interval'])) {
                $type->syncToStripe();
            }
        });
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

    /**
     * Create or refresh this plan's Stripe Product + Price, so admin edits
     * to the name/price/interval are reflected on Stripe. Stripe Prices are
     * immutable, so a change to price/interval creates a new Price.
     */
    public function syncToStripe(): void
    {
        $stripe = Cashier::stripe();

        if ($this->stripe_product_id) {
            $stripe->products->update($this->stripe_product_id, ['name' => $this->name]);
        } else {
            $this->stripe_product_id = $stripe->products->create(['name' => $this->name])->id;
        }

        $price = $stripe->prices->create([
            'product' => $this->stripe_product_id,
            'unit_amount' => $this->price_cents,
            'currency' => 'eur',
            ...$this->isRecurring() ? ['recurring' => ['interval' => $this->billing_interval]] : [],
        ]);

        $this->stripe_price_id = $price->id;
        $this->saveQuietly();
    }
}
