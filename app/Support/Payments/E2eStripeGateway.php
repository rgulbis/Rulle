<?php

namespace App\Support\Payments;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Stripe\Checkout\Session;

/**
 * A stand-in for Stripe used ONLY when the app runs in E2E mode (the browser
 * tests in tests/e2e) — see AppServiceProvider::registerE2eMode(). It never
 * talks to the network: checkout sessions live in the cache, and a fake
 * "Stripe" payment page (routes/e2e.php) completes them the way Stripe's
 * webhook and redirect would.
 */
class E2eStripeGateway extends StripeGateway
{
    public function createReservationCheckout(
        User $user,
        int $amountCents,
        string $description,
        string $successUrl,
        string $cancelUrl,
        int $expiresAt,
    ): array {
        $id = 'cs_e2e_'.bin2hex(random_bytes(6));

        Cache::forever("e2e-stripe:session:{$id}", [
            'id' => $id,
            'status' => 'open',
            'payment_status' => 'unpaid',
            'payment_intent' => null,
            'customer' => null,
            'url' => url("/__e2e/pay/{$id}"),
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'amount' => $amountCents,
        ]);

        return ['id' => $id, 'url' => url("/__e2e/pay/{$id}")];
    }

    public function checkoutSession(string $sessionId): Session
    {
        return Session::constructFrom(Cache::get("e2e-stripe:session:{$sessionId}", [
            'id' => $sessionId, 'status' => 'open', 'payment_status' => 'unpaid', 'payment_intent' => null, 'customer' => null,
        ]));
    }

    public function refund(string $paymentIntentId, string $idempotencyKey, ?int $amountCents = null): void
    {
        $refunds = Cache::get('e2e-stripe:refunds', []);
        $refunds[] = ['payment_intent' => $paymentIntentId, 'key' => $idempotencyKey];
        Cache::forever('e2e-stripe:refunds', $refunds);
    }

    public function refundedCents(string $paymentIntentId): int
    {
        return 0;
    }

    public function cancelSubscriptionNow(string $stripeSubscriptionId): void {}

    /**
     * Marks a session paid, as Stripe does on a successful payment.
     *
     * @return array<string, mixed>|null
     */
    public static function markPaid(string $sessionId): ?array
    {
        $session = Cache::get("e2e-stripe:session:{$sessionId}");

        if (! $session) {
            return null;
        }

        $session['status'] = 'complete';
        $session['payment_status'] = 'paid';
        $session['payment_intent'] = 'pi_e2e_'.substr($sessionId, -12);
        Cache::forever("e2e-stripe:session:{$sessionId}", $session);

        return $session;
    }
}
