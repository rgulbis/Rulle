<?php

namespace App\Support\Payments;

use App\Models\Purchase;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Money going back to a customer. The caller first records the *intent*
 * (payment_status = refund_pending, in the same transaction that cancels the
 * booking); only then does this talk to Stripe. If Stripe is down, the row is
 * left as refund_failed — visible to admins and retried by
 * `payments:retry-refunds` — instead of the booking being cancelled with the
 * money silently kept and nothing recording that a refund is owed.
 */
class Refunds
{
    public function __construct(private StripeGateway $stripe) {}

    /**
     * Whether the refund went through.
     */
    public function issue(Reservation|Purchase $payable): bool
    {
        if (! in_array($payable->payment_status, ['refund_pending', 'refund_failed'], true)) {
            return $payable->payment_status === 'refunded';
        }

        try {
            $paymentIntent = $payable->stripe_payment_intent_id ?? $this->resolvePaymentIntent($payable);

            if (! $paymentIntent) {
                throw new \RuntimeException('No payment to refund is on record.');
            }

            $key = 'refund-'.class_basename($payable).'-'.$payable->id;
            $this->stripe->refund($paymentIntent, $key);
        } catch (Throwable $e) {
            Log::error('Refund failed.', [
                'type' => class_basename($payable),
                'id' => $payable->id,
                'exception' => $e,
            ]);

            $payable->forceFill(['payment_status' => 'refund_failed'])->save();

            return false;
        }

        $payable->forceFill([
            'payment_status' => 'refunded',
            'refunded_cents' => $payable->price_cents ?? 0,
            'refunded_at' => now(),
            'stripe_payment_intent_id' => $paymentIntent,
        ])->save();

        return true;
    }

    /**
     * Admin refund of a one-time pass: revokes it and refunds in full.
     */
    public function refundPurchase(Purchase $purchase): bool
    {
        DB::transaction(function () use ($purchase) {
            $purchase->refresh();

            if ($purchase->status === 'active' || $purchase->status === 'used_up') {
                $purchase->forceFill([
                    'status' => 'refunded',
                    'payment_status' => $purchase->payment_status === 'paid' ? 'refund_pending' : $purchase->payment_status,
                ])->save();
            }
        });

        return $this->issue($purchase);
    }

    /**
     * Rows paid before payment intents were stored locally only know their
     * checkout session.
     */
    private function resolvePaymentIntent(Reservation|Purchase $payable): ?string
    {
        if (! $payable->stripe_checkout_session_id) {
            return null;
        }

        $intent = $this->stripe->checkoutSession($payable->stripe_checkout_session_id)->payment_intent;

        return is_string($intent) ? $intent : ($intent->id ?? null);
    }
}
