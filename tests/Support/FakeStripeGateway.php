<?php

namespace Tests\Support;

use App\Models\Reservation;
use App\Models\User;
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

    /** @var list<array{kind: string, session: string, user: int, price: string|null}> */
    public array $createdCheckouts = [];

    /** @var list<string> */
    public array $cancelledSubscriptions = [];

    /**
     * An open (unpaid) session, as Stripe returns right after creating one.
     */
    private function openSession(string $kind, User $user, ?string $price): Session
    {
        $id = 'cs_fake_'.(count($this->createdCheckouts) + 1);
        $this->createdCheckouts[] = ['kind' => $kind, 'session' => $id, 'user' => $user->id, 'price' => $price];

        return $this->addSession($id, [
            'status' => 'open',
            'payment_status' => 'unpaid',
            'url' => 'https://checkout.test/'.$id,
            'customer' => $user->stripe_id,
        ]);
    }

    public function createReservationCheckout(User $user, Reservation $reservation, string $successUrl, string $cancelUrl): Session
    {
        return $this->openSession('reservation', $user, null);
    }

    public function createSubscriptionCheckout(User $user, string $priceId, string $successUrl, string $cancelUrl): Session
    {
        return $this->openSession('subscription', $user, $priceId);
    }

    public function createPassCheckout(User $user, string $priceId, string $successUrl, string $cancelUrl): Session
    {
        return $this->openSession('pass', $user, $priceId);
    }

    public function cancelSubscriptionNow(string $stripeSubscriptionId): void
    {
        $this->cancelledSubscriptions[] = $stripeSubscriptionId;
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
