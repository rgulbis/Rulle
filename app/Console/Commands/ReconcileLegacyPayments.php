<?php

namespace App\Console\Commands;

use App\Models\Purchase;
use App\Models\Reservation;
use App\Support\Payments\CheckoutFulfillment;
use App\Support\Payments\StripeGateway;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('payments:reconcile-legacy {--apply : Write the results (default is a read-only report)}')]
#[Description('Work out from Stripe what happened to old cancelled reservations / abandoned passes (paid? refunded?)')]
class ReconcileLegacyPayments extends Command
{
    /**
     * Before payment tracking existed, "cancelled" could mean refunded,
     * forfeited (paid, cancelled too late) or never paid, and nothing local
     * said which. The migration therefore left those rows as `unpaid`.
     * This asks Stripe, row by row, and classifies them:
     *
     *  - never paid          -> left alone
     *  - paid, not refunded  -> payment_status = paid (kept money, counts as revenue)
     *  - refunded (fully)    -> payment_status = refunded
     *  - a pass that was paid but never activated -> reported, and activated with --apply
     */
    public function handle(StripeGateway $stripe, CheckoutFulfillment $fulfillment): int
    {
        $apply = (bool) $this->option('apply');
        $rows = [];
        $failed = 0;

        $candidates = [
            ...Reservation::where('status', 'cancelled')->where('payment_status', 'unpaid')->whereNotNull('stripe_checkout_session_id')->get()->all(),
            ...Purchase::where('status', 'abandoned')->where('payment_status', 'unpaid')->whereNotNull('stripe_checkout_session_id')->get()->all(),
        ];

        foreach ($candidates as $item) {
            $label = class_basename($item).' #'.$item->id;

            try {
                $session = $stripe->checkoutSession($item->stripe_checkout_session_id);

                if ($session->payment_status !== 'paid') {
                    $rows[] = [$label, 'never paid', '—'];

                    continue;
                }

                $intent = is_string($session->payment_intent) ? $session->payment_intent : ($session->payment_intent->id ?? null);
                $refunded = $intent ? $stripe->refundedCents($intent) : 0;
                $price = (int) ($item->price_cents ?? 0);

                if ($item instanceof Purchase && $refunded === 0) {
                    $rows[] = [$label, 'PAID BUT PASS NEVER ACTIVATED', $apply ? 'activated' : 'would activate'];

                    if ($apply) {
                        $fulfillment->fulfill($item->stripe_checkout_session_id, $intent);
                    }

                    continue;
                }

                $fullyRefunded = $refunded > 0 && $refunded >= $price;
                $newStatus = $fullyRefunded ? 'refunded' : 'paid';
                $rows[] = [$label, $fullyRefunded ? "refunded {$refunded}c" : ($refunded > 0 ? "paid, partly refunded {$refunded}c" : 'paid, money kept'), $apply ? "-> {$newStatus}" : "would set {$newStatus}"];

                if ($apply) {
                    $item->forceFill([
                        'payment_status' => $newStatus,
                        'stripe_payment_intent_id' => $intent,
                        'paid_at' => $item->paid_at ?? $item->created_at,
                        'refunded_cents' => $refunded,
                        'refunded_at' => $refunded > 0 ? ($item->refunded_at ?? $item->updated_at) : null,
                    ])->save();
                }
            } catch (Throwable $e) {
                $failed++;
                $rows[] = [$label, 'ERROR: '.$e->getMessage(), '—'];
            }
        }

        if ($rows === []) {
            $this->info('Nothing to reconcile.');

            return self::SUCCESS;
        }

        $this->table(['Row', 'Stripe says', $apply ? 'Result' : 'Would do'], $rows);

        if (! $apply) {
            $this->comment('Read-only. Re-run with --apply to write these.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
