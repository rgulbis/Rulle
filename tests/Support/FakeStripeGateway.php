<?php

namespace Tests\Support;

use App\Models\Reservation;
use App\Models\User;
use App\Support\Payments\StripeGateway;
use App\Support\Payments\StripePayment;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Invoice;
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

    /** @var list<array{customer: string, name: string, email: string}> */
    public array $anonymisedCustomers = [];

    /** Throw on the next anonymiseCustomer() call. */
    public bool $anonymiseFails = false;

    /** @var array<string, string> product id by idempotency key */
    public array $products = [];

    /** @var array<string, string> product id => current name */
    public array $productNames = [];

    /** @var array<string, array{id: string, product: string, amount: int, interval: string|null}> price by idempotency key */
    public array $prices = [];

    /** Fail the next createPrice() calls the way a Stripe outage would. */
    public bool $priceFails = false;

    public function anonymiseCustomer(string $customerId, string $name, string $email): void
    {
        if ($this->anonymiseFails) {
            throw new ApiConnectionException('Could not connect to Stripe.');
        }

        $this->anonymisedCustomers[] = ['customer' => $customerId, 'name' => $name, 'email' => $email];
    }

    public function createProduct(string $name, string $idempotencyKey): string
    {
        $id = $this->products[$idempotencyKey] ??= 'prod_fake_'.(count($this->products) + 1);
        $this->productNames[$id] = $name;

        return $id;
    }

    public function renameProduct(string $productId, string $name): void
    {
        $this->productNames[$productId] = $name;
    }

    public function createPrice(string $productId, int $amountCents, ?string $interval, string $idempotencyKey): string
    {
        if ($this->priceFails) {
            throw new ApiConnectionException('Could not connect to Stripe.');
        }

        return ($this->prices[$idempotencyKey] ??= [
            'id' => 'price_fake_'.(count($this->prices) + 1),
            'product' => $productId,
            'amount' => $amountCents,
            'interval' => $interval,
        ])['id'];
    }

    /** @var list<Invoice> */
    public array $paidInvoices = [];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addPaidInvoice(string $customer, int $amountPaid, array $attributes = []): Invoice
    {
        return $this->paidInvoices[] = Invoice::constructFrom(array_merge([
            'id' => 'in_fake_'.(count($this->paidInvoices) + 1),
            'customer' => $customer,
            'amount_paid' => $amountPaid,
            'post_payment_credit_notes_amount' => 0,
            'status' => 'paid',
            'created' => now()->getTimestamp(),
        ], $attributes));
    }

    public function paidInvoices(?int $sinceTimestamp = null, ?int $untilTimestamp = null): iterable
    {
        return array_values(array_filter($this->paidInvoices, fn ($invoice) => ($sinceTimestamp === null || $invoice->created >= $sinceTimestamp)
            && ($untilTimestamp === null || $invoice->created <= $untilTimestamp)));
    }

    /** @var list<string> */
    public array $resumedSubscriptions = [];

    public function resumeSubscription(string $stripeSubscriptionId): void
    {
        $this->resumedSubscriptions[] = $stripeSubscriptionId;
    }

    public function cancelSubscriptionNow(string $stripeSubscriptionId): void
    {
        $this->cancelledSubscriptions[] = $stripeSubscriptionId;
    }

    /** @var array<string, int> cents already refunded at Stripe, by session id */
    public array $refundedAtStripe = [];

    public function paymentSnapshot(string $sessionId): ?StripePayment
    {
        $session = $this->sessions[$sessionId] ?? null;

        if ($session === null) {
            return null;
        }

        return new StripePayment(
            $session,
            $session->payment_status === 'paid',
            $this->refundedAtStripe[$sessionId] ?? 0,
        );
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
