<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Cashier;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int $price_cents
 * @property string $billing_interval
 * @property int|null $visit_limit
 * @property string|null $stripe_product_id
 * @property string|null $stripe_price_id
 * @property bool $active
 */
#[Fillable(['name', 'description', 'price_cents', 'billing_interval', 'visit_limit', 'active'])]
class SubscriptionType extends Model
{
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'visit_limit' => 'integer',
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
