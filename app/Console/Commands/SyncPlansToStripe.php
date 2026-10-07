<?php

namespace App\Console\Commands;

use App\Models\SubscriptionType;
use App\Support\Payments\PlanStripeSync;
use Illuminate\Console\Command;
use Throwable;

class SyncPlansToStripe extends Command
{
    protected $signature = 'plans:sync-stripe';

    protected $description = 'Create or update the Stripe Product and Price of every plan that is out of step with Stripe';

    public function handle(PlanStripeSync $sync): int
    {
        $synced = 0;
        $failed = 0;

        SubscriptionType::query()->each(function (SubscriptionType $plan) use ($sync, &$synced, &$failed) {
            if (! $plan->needsStripeSync()) {
                return;
            }

            try {
                $sync->sync($plan);
                $synced++;
            } catch (Throwable $e) {
                report($e);
                $this->error("Plan {$plan->id} ({$plan->name}): {$e->getMessage()}");
                $failed++;
            }
        });

        $this->info("Plans synced: {$synced}, failed: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
