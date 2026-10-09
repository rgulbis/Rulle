<?php

namespace App\Support\Payments;

use App\Models\SubscriptionType;
use Illuminate\Support\Facades\Cache;
use Stripe\Exception\ApiErrorException;

/**
 * Brings a plan's Stripe Product and Price in line with the plan.
 *
 * Built to be repeated: each step is recorded on the plan as soon as Stripe
 * has confirmed it, so a failure halfway (the Product exists, the Price call
 * timed out) leaves the plan pointing at its Product and still marked as
 * needing a sync - the next run carries on from there instead of making a
 * second Product, and nothing is left at Stripe that the plan doesn't know.
 *
 * A Stripe Price is immutable and carries no name, so renaming a plan only
 * renames its Product; a new Price is made only when the amount or billing
 * interval differs from what the current Price was made with.
 */
class PlanStripeSync
{
    public function __construct(private readonly StripeGateway $stripe) {}

    /**
     * @throws ApiErrorException when Stripe refuses or can't be reached (what was done before the failure stays recorded)
     */
    public function sync(SubscriptionType $plan): void
    {
        // Two syncs of one plan at once (the admin's click and the scheduled
        // retry) would each make a Product. The one that loses the lock
        // leaves the work to the other.
        $lock = Cache::lock("plan-stripe-sync:{$plan->id}", 60);

        if (! $lock->get()) {
            return;
        }

        try {
            // Decide from the plan as it is now, not as it was when this was queued.
            $plan->refresh();

            $this->syncProduct($plan);
            $this->syncPrice($plan);
        } finally {
            $lock->release();
        }
    }

    private function syncProduct(SubscriptionType $plan): void
    {
        if (! $plan->stripe_product_id) {
            $plan->stripe_product_id = $this->stripe->createProduct($plan->name, "plan-{$plan->id}-product-".md5($plan->name));
            $plan->stripe_synced_name = $plan->name;
            $plan->saveQuietly();

            return;
        }

        if ($plan->stripe_synced_name !== $plan->name) {
            $this->stripe->renameProduct($plan->stripe_product_id, $plan->name);
            $plan->stripe_synced_name = $plan->name;
            $plan->saveQuietly();
        }
    }

    private function syncPrice(SubscriptionType $plan): void
    {
        $upToDate = $plan->stripe_price_id
            && $plan->stripe_synced_price_cents === $plan->price_cents
            && $plan->stripe_synced_interval === $plan->billing_interval;

        if ($upToDate) {
            return;
        }

        $interval = $plan->isRecurring() ? $plan->billing_interval : null;

        $plan->stripe_price_id = $this->stripe->createPrice(
            $plan->stripe_product_id,
            $plan->price_cents,
            $interval,
            "plan-{$plan->id}-price-{$plan->price_cents}-{$plan->billing_interval}-{$plan->stripe_product_id}",
        );
        $plan->stripe_synced_price_cents = $plan->price_cents;
        $plan->stripe_synced_interval = $plan->billing_interval;
        $plan->saveQuietly();
    }
}
