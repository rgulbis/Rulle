<?php

namespace Tests\Support;

use App\Support\Payments\StripeGateway;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Refund;

/**
 * An in-memory Stripe: checkout sessions the test registers, and a refund
 * endpoint that honours idempotency keys like the real one.
 */
class FakeStripeGateway extends StripeGateway
{
    /** @var array<string, Session> */
    public array $sessions = [];

    /** @var list<array{payment_intent: string, amount: int, key: string}> */
    public array $refundRequests = [];

    /** @var array<string, Refund> */
    private array $refundsByKey = [];

    /** Throw this on the next refund calls instead of succeeding. */
    public ?string $refundFailure = null;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addSession(string $id, array $attributes = []): Session
    {
        return $this->sessions[$id] = Session::constructFrom(array_merge([
            'id' => $id,
            'status' => 'complete',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_'.$id,
            'customer' => null,
        ], $attributes));
    }

    public function retrieveCheckoutSession(string $sessionId): Session
    {
        return $this->sessions[$sessionId]
            ?? throw new ApiConnectionException("Unknown fake session {$sessionId}");
    }

    public function createRefund(string $paymentIntentId, int $amountCents, string $idempotencyKey): Refund
    {
        $this->refundRequests[] = ['payment_intent' => $paymentIntentId, 'amount' => $amountCents, 'key' => $idempotencyKey];

        if ($this->refundFailure === 'network') {
            throw new ApiConnectionException('Could not connect to Stripe.');
        }

        if ($this->refundFailure === 'already_refunded') {
            throw InvalidRequestException::factory('Charge has already been refunded.', 400, null, null, null, 'charge_already_refunded');
        }

        return $this->refundsByKey[$idempotencyKey] ??= Refund::constructFrom([
            'id' => 're_'.(count($this->refundsByKey) + 1),
            'amount' => $amountCents,
            'status' => 'succeeded',
        ]);
    }

    /**
     * How many distinct refunds exist at Stripe (a replayed idempotency key
     * is not a second refund).
     */
    public function distinctRefundCount(): int
    {
        return count($this->refundsByKey);
    }
}
