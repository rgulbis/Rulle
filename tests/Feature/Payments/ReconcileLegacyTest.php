<?php

use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Payments\StripeGateway;
use App\Support\Payments\StripePayment;
use Stripe\Exception\ApiConnectionException;
use Tests\Support\FakeStripeGateway;

/*
| `payments:reconcile-legacy` settles rows from before payment tracking by
| asking Stripe what really happened. It reports by default and only writes
| with --apply.
*/

beforeEach(function () {
    makeReservationSettings(['cancellation_cutoff_hours' => 24]);
    $this->stripe = fakeStripe();
});

/** A row the command will look at: older than the in-flight window. */
function longAgo(Reservation|Purchase $row): void
{
    $row->newQuery()->whereKey($row->getKey())->update(['updated_at' => now()->subDays(2)]);
}

function legacyCancelled(array $attributes = []): Reservation
{
    $start = $attributes['starts_at'] ?? now()->addDays(3);
    unset($attributes['starts_at']);

    $reservation = makeReservation(User::factory()->create(), $start, $start->addHour(), array_merge([
        'status' => 'cancelled',
        'price_cents' => 3000,
        'stripe_checkout_session_id' => 'cs_legacy_'.str()->random(8),
    ], $attributes));

    return $reservation->refresh();
}

function legacyPass(string $status, string $session): Purchase
{
    $purchase = Purchase::create([
        'user_id' => User::factory()->create()->id,
        'subscription_type_id' => makeSubscriptionType(['visit_limit' => 5])->id,
        'stripe_checkout_session_id' => $session,
        'price_cents' => 500,
        'status' => $status,
        'payment_status' => 'unpaid',
    ]);
    longAgo($purchase);

    return $purchase->refresh();
}

test('a dry run reports what it would do and changes nothing', function () {
    $reservation = legacyCancelled();
    longAgo($reservation);
    $this->stripe->addSession($reservation->stripe_checkout_session_id);
    $this->stripe->refundedAtStripe[$reservation->stripe_checkout_session_id] = 3000;

    $this->artisan('payments:reconcile-legacy')
        ->expectsOutputToContain('would change')
        ->assertSuccessful();

    expect($reservation->refresh()->payment_status)->toBe('unpaid');
});

test('--refund-owed needs --apply', function () {
    $this->artisan('payments:reconcile-legacy --refund-owed')->assertFailed();
});

test('a cancelled reservation Stripe fully refunded is recorded as refunded', function () {
    $reservation = legacyCancelled();
    $this->stripe->addSession($reservation->stripe_checkout_session_id);
    $this->stripe->refundedAtStripe[$reservation->stripe_checkout_session_id] = 3000;

    $this->artisan('payments:reconcile-legacy --apply')->assertSuccessful();

    $reservation->refresh();
    expect($reservation->status)->toBe('cancelled');
    expect($reservation->payment_status)->toBe('refunded');
    expect($reservation->refunded_cents)->toBe(3000);
    expect($reservation->refunded_at)->not->toBeNull();
    expect($this->stripe->refundRequests)->toBeEmpty();
});

test('a partial refund at Stripe is recorded as partially refunded', function () {
    $reservation = legacyCancelled();
    $this->stripe->addSession($reservation->stripe_checkout_session_id);
    $this->stripe->refundedAtStripe[$reservation->stripe_checkout_session_id] = 1000;

    $this->artisan('payments:reconcile-legacy --apply')->assertSuccessful();

    $reservation->refresh();
    expect($reservation->payment_status)->toBe('partially_refunded');
    expect($reservation->refunded_cents)->toBe(1000);
});

test('money kept after a late cancellation counts as paid, so revenue sees it', function () {
    $reservation = legacyCancelled(['starts_at' => now()->addHours(3)]);
    $this->stripe->addSession($reservation->stripe_checkout_session_id);

    $this->artisan('payments:reconcile-legacy --apply')->assertSuccessful();

    $reservation->refresh();
    expect($reservation->payment_status)->toBe('paid');
    expect($reservation->refunded_cents)->toBe(0);
    expect($this->stripe->refundRequests)->toBeEmpty();
});

test('a refund that was owed is left alone until --refund-owed is given', function () {
    $reservation = legacyCancelled();
    $this->stripe->addSession($reservation->stripe_checkout_session_id);

    $this->artisan('payments:reconcile-legacy --apply')
        ->expectsOutputToContain('needs --refund-owed')
        ->assertSuccessful();

    expect($reservation->refresh()->payment_status)->toBe('unpaid');
    expect($this->stripe->refundRequests)->toBeEmpty();

    $this->artisan('payments:reconcile-legacy --apply --refund-owed')->assertSuccessful();

    $reservation->refresh();
    expect($reservation->payment_status)->toBe('refunded');
    expect($reservation->refunded_cents)->toBe(3000);
    expect($this->stripe->refundRequests)->toHaveCount(1);
});

test('a cancelled reservation that was never paid is left as it is', function () {
    $reservation = legacyCancelled();
    $this->stripe->addSession($reservation->stripe_checkout_session_id, ['status' => 'expired', 'payment_status' => 'unpaid']);

    $this->artisan('payments:reconcile-legacy --apply')->assertSuccessful();

    expect($reservation->refresh()->payment_status)->toBe('unpaid');
});

test('an abandoned pass that Stripe says was paid is activated', function () {
    $purchase = legacyPass('abandoned', 'cs_pass_paid');
    $this->stripe->addSession('cs_pass_paid');

    $this->artisan('payments:reconcile-legacy --apply')->assertSuccessful();

    $purchase->refresh();
    expect($purchase->status)->toBe('active');
    expect($purchase->payment_status)->toBe('paid');
    expect($purchase->visits_remaining)->toBe(5);
});

test('a pending pass that was never paid stays as it is', function () {
    $purchase = legacyPass('pending', 'cs_pass_unpaid');
    $this->stripe->addSession('cs_pass_unpaid', ['status' => 'expired', 'payment_status' => 'unpaid']);

    $this->artisan('payments:reconcile-legacy --apply')->assertSuccessful();

    expect($purchase->refresh()->status)->toBe('pending');
});

test('a pending reservation that was paid is activated', function () {
    $reservation = legacyCancelled(['status' => 'pending']);
    longAgo($reservation);
    $this->stripe->addSession($reservation->stripe_checkout_session_id);

    $this->artisan('payments:reconcile-legacy --apply')->assertSuccessful();

    $reservation->refresh();
    expect($reservation->status)->toBe('active');
    expect($reservation->payment_status)->toBe('paid');
});

test('rows touched a moment ago are skipped: their checkout may still be open', function () {
    $reservation = legacyCancelled(['status' => 'pending']);
    $this->stripe->addSession($reservation->stripe_checkout_session_id);

    $this->artisan('payments:reconcile-legacy --apply')->expectsOutputToContain('Nothing to reconcile')->assertSuccessful();

    expect($reservation->refresh()->status)->toBe('pending');
});

test('a Stripe outage on one row is reported and the rest are still processed', function () {
    $unreachable = legacyCancelled(['stripe_checkout_session_id' => 'cs_unreachable']);
    $other = legacyCancelled(['starts_at' => now()->addHours(3)]);

    $gateway = new class extends FakeStripeGateway
    {
        public function paymentSnapshot(string $sessionId): ?StripePayment
        {
            if ($sessionId === 'cs_unreachable') {
                throw new ApiConnectionException('Could not connect to Stripe.');
            }

            return parent::paymentSnapshot($sessionId);
        }
    };
    $gateway->addSession($other->stripe_checkout_session_id);
    app()->instance(StripeGateway::class, $gateway);

    $this->artisan('payments:reconcile-legacy --apply')
        ->expectsOutputToContain('Could not be checked')
        ->assertFailed();

    expect($unreachable->refresh()->payment_status)->toBe('unpaid');
    expect($other->refresh()->payment_status)->toBe('paid');
});

test('running it twice changes nothing the second time', function () {
    $reservation = legacyCancelled();
    $this->stripe->addSession($reservation->stripe_checkout_session_id);
    $this->stripe->refundedAtStripe[$reservation->stripe_checkout_session_id] = 3000;

    $this->artisan('payments:reconcile-legacy --apply')->assertSuccessful();
    $this->artisan('payments:reconcile-legacy --apply')->expectsOutputToContain('Nothing to reconcile')->assertSuccessful();
});
