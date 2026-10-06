<?php

namespace App\Console\Commands;

use App\Models\Purchase;
use App\Models\Reservation;
use App\Support\Payments\Refunds;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payments:retry-refunds')]
#[Description('Retry refunds that are still owed to customers (pending or failed)')]
class RetryRefunds extends Command
{
    public function handle(Refunds $refunds): int
    {
        $stillFailing = 0;
        $statuses = ['refund_pending', 'refund_failed'];

        foreach ([Reservation::class, Purchase::class] as $model) {
            // Not touching rows touched in the last minute: a refund that is
            // being issued right now by a request shouldn't be raced.
            $model::whereIn('payment_status', $statuses)
                ->where('updated_at', '<', now()->subMinute())
                ->each(function (Reservation|Purchase $payable) use ($refunds, &$stillFailing) {
                    if (! $refunds->issue($payable)) {
                        $stillFailing++;
                    }
                });
        }

        if ($stillFailing > 0) {
            $this->error("{$stillFailing} refund(s) still failing — see the log.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
