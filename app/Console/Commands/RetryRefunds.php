<?php

namespace App\Console\Commands;

use App\Models\Purchase;
use App\Models\Reservation;
use App\Support\Payments\RefundOutcome;
use App\Support\Payments\Refunds;
use Illuminate\Console\Command;

class RetryRefunds extends Command
{
    protected $signature = 'payments:retry-refunds';

    protected $description = 'Retry refunds that were recorded but never confirmed by Stripe';

    public function handle(Refunds $refunds): int
    {
        $failed = 0;
        $settled = 0;

        foreach ([Purchase::class, Reservation::class] as $model) {
            $model::query()
                ->whereNotNull('refund_requested_cents')
                ->whereColumn('refunded_cents', '<', 'refund_requested_cents')
                // Not rows a request is refunding right now.
                ->where('updated_at', '<=', now()->subMinutes(5))
                ->each(function (Purchase|Reservation $payable) use ($refunds, &$failed, &$settled) {
                    $refunds->settle($payable) === RefundOutcome::Refunded ? $settled++ : $failed++;
                });
        }

        $this->info("Refunds settled: {$settled}, still failing: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
