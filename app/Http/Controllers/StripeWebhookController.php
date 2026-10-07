<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Payments\CheckoutFulfillment;
use App\Support\Payments\StripeGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Stripe\Checkout\Session;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe's webhook is where money becomes real here: a paid checkout is
 * fulfilled from these events whether or not the customer's browser ever
 * makes it back to the success URL. The endpoint must be subscribed to
 * `checkout.session.completed`, `checkout.session.async_payment_succeeded`
 * and `checkout.session.expired` (plus Cashier's own subscription events),
 * and `STRIPE_WEBHOOK_SECRET` must be set so the signature is verified.
 */
class StripeWebhookController extends CashierWebhookController
{
    public function __construct(
        private readonly CheckoutFulfillment $fulfillment,
        private readonly StripeGateway $stripe,
    ) {
        parent::__construct();
    }

    /**
     * Fires when the customer finishes Checkout. For card payments the money
     * is already collected (`payment_status` = paid) and the pass or
     * reservation is fulfilled right here. For delayed methods it is still
     * `unpaid`, and nothing is granted until the async event below.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCheckoutSessionCompleted(array $payload): Response
    {
        return $this->fulfill($payload);
    }

    /**
     * Fires when a delayed payment method (bank debit, voucher...) finally
     * clears.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCheckoutSessionAsyncPaymentSucceeded(array $payload): Response
    {
        return $this->fulfill($payload);
    }

    /**
     * A one-time-pass or reservation checkout a customer starts but never
     * finishes (closes the tab, card declines with no retry, etc.) would
     * stay `pending` forever — nothing else ever revisits it. Stripe expires
     * an unfinished Checkout Session on its own (by default 24 hours after
     * it was created) and fires this event, the only reliable way to know a
     * checkout is truly dead rather than just slow.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCheckoutSessionExpired(array $payload): Response
    {
        $sessionId = $payload['data']['object']['id'] ?? null;

        if ($sessionId) {
            Purchase::where('stripe_checkout_session_id', $sessionId)
                ->where('status', 'pending')
                ->update(['status' => 'abandoned']);

            Reservation::where('stripe_checkout_session_id', $sessionId)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);
        }

        return $this->successMethod();
    }

    /**
     * The customer is meant to have one live subscription. The checkout
     * route makes a second one hard to start, but the webhook is the last
     * place it can be stopped: if this event is for a second live
     * subscription it is NOT recorded (the customer keeps the one they
     * have) and it is cancelled at Stripe so it never bills again. The
     * payment it already took has to be refunded by hand, hence `critical`.
     *
     * Checking and recording are one transaction, so two such events handled
     * at the same moment can't both see "no live subscription yet".
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        $response = null;

        $duplicateOf = DB::transaction(function () use ($payload, &$response) {
            $existing = $this->liveSubscriptionBesides($payload);

            if ($existing !== null) {
                return $existing;
            }

            $response = parent::handleCustomerSubscriptionCreated($payload);

            $this->recordBilledAmount($payload);

            return null;
        });

        if ($duplicateOf === null) {
            return $response;
        }

        $this->stripe->cancelSubscriptionNow($payload['data']['object']['id']);

        Log::critical('A second live subscription was created for a customer and has been cancelled at Stripe. Its first payment must be refunded manually.', [
            'user_id' => $duplicateOf['user_id'],
            'kept_subscription' => $duplicateOf['stripe_id'],
            'cancelled_subscription' => $payload['data']['object']['id'],
        ]);

        return $this->successMethod();
    }

    /**
     * The customer's other live subscription of the same type, if this event
     * would give them a second one. "Live" matches the partial unique index
     * on `subscriptions`: not ended or set to end, and active/trialing/past due.
     *
     * @param  array<string, mixed>  $payload
     * @return array{user_id: int, stripe_id: string}|null
     */
    private function liveSubscriptionBesides(array $payload): ?array
    {
        $data = $payload['data']['object'];

        if (! in_array($data['status'] ?? null, ['active', 'trialing', 'past_due', 'incomplete'], true)) {
            return null;
        }

        $user = User::where('stripe_id', $data['customer'])->first();

        if (! $user) {
            return null;
        }

        $type = $data['metadata']['type'] ?? $data['metadata']['name'] ?? 'default';

        $existingId = $user->subscriptions()
            ->where('type', $type)
            ->where('stripe_id', '!=', $data['id'])
            ->whereNull('ends_at')
            ->whereIn('stripe_status', ['active', 'trialing', 'past_due'])
            ->value('stripe_id');

        return is_string($existingId) ? ['user_id' => $user->id, 'stripe_id' => $existingId] : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionUpdated(array $payload): ?Response
    {
        $response = parent::handleCustomerSubscriptionUpdated($payload);

        $this->recordBilledAmount($payload);

        return $response;
    }

    /**
     * Stripe prices are immutable and plans get repriced, so what a
     * subscriber is actually billed is stored on their subscription instead
     * of being looked up from today's plan price.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordBilledAmount(array $payload): void
    {
        $subscription = $payload['data']['object'];
        $items = $subscription['items']['data'] ?? [];

        if (count($items) !== 1 || ! isset($items[0]['price']['unit_amount'])) {
            return;
        }

        DB::table('subscriptions')
            ->where('stripe_id', $subscription['id'])
            ->update(['price_cents' => $items[0]['price']['unit_amount'] * ($items[0]['quantity'] ?? 1)]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fulfill(array $payload): Response
    {
        $this->fulfillment->fulfill(Session::constructFrom($payload['data']['object']));

        return $this->successMethod();
    }
}
