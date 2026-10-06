<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        // A plan with sales is part of the financial record and of live
        // Stripe subscriptions — it can be deactivated (hidden from the shop)
        // but not deleted, and its billing type can't be switched under
        // existing buyers (a recurring subscription can't be swapped onto a
        // one-time Stripe Price).
        static::deleting(function (SubscriptionType $type) {
            if ($type->hasSales()) {
                throw new DomainException("\"{$type->name}\" has been sold — deactivate it instead of deleting it.");
            }
        });

        static::updating(function (SubscriptionType $type) {
            if ($type->isDirty('billing_interval') && $type->hasSales()) {
                throw new DomainException("\"{$type->name}\" has been sold, so its billing type can't change — create a new plan instead.");
            }
        });

        static::saved(function (SubscriptionType $type) {
            // Stripe Prices are immutable: only a new price or interval needs
            // a new Price. A rename just renames the Product.
            if (! $type->stripe_price_id || $type->wasChanged(['price_cents', 'billing_interval'])) {
                $type->syncToStripe();
            } elseif ($type->wasChanged('name') && $type->stripe_product_id) {
                Cashier::stripe()->products->update($type->stripe_product_id, ['name' => $type->name]);
            }
        });
    }

    /**
     * Whether anyone has ever bought this plan — a paid pass, or a Stripe
     * subscription on its product.
     */
    public function hasSales(): bool
    {
        if ($this->purchases()->exists()) {
            return true;
        }

        return $this->stripe_product_id !== null
            && DB::table('subscription_items')->where('stripe_product', $this->stripe_product_id)->exists();
    }

    /**
     * @return HasMany<Purchase, $this>
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /**
     * A plan that would take a customer's money and then give them nothing
     * (a one-time pass with no visits) must never be sold.
     */
    public function isSellable(): bool
    {
        if ($this->price_cents < 50) {
            return false;
        }

        if ($this->isRecurring() || $this->unlimited_entries) {
            return true;
        }

        return $this->visit_limit !== null && $this->visit_limit >= 1;
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
            // Saved before the Price call: if that fails, the next sync
            // reuses this Product instead of leaving it orphaned on Stripe
            // and creating a second one.
            $this->saveQuietly();
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
