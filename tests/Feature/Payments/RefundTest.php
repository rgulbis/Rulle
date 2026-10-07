<?php

use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Payments\Refunds;
use Livewire\Livewire;

/*
| A refund is recorded as an intent in the database first, then sent to
| Stripe with an idempotency key. If Stripe doesn't confirm, the row keeps
| owing the money and `payments:retry-refunds` finishes the job.
*/

function paidReservation(User $owner, array $attributes = []): Reservation
{
    return makeReservation($owner, now()->addDays(3), now()->addDays(3)->addHour(), array_merge([
        'status' => 'active',
        'price_cents' => 3000,
        'stripe_checkout_session_id' => 'cs_refund_'.str()->random(8),
    ], $attributes));
}

function paidPurchase(string $sessionId): Purchase
{
    return Purchase::create([
        'user_id' => User::factory()->create()->id,
        'subscription_type_id' => makeSubscriptionType()->id,
        'stripe_checkout_session_id' => $sessionId,
        'price_cents' => 1000,
        'status' => 'active',
        'payment_status' => 'paid',
        'visits_remaining' => 1,
    ]);
}

beforeEach(function () {
    makeReservationSettings(['cancellation_cutoff_hours' => 24]);
    $this->stripe = fakeStripe();
});

test('cancelling early refunds the reservation, with the intent recorded and an idempotency key', function () {
    $owner = User::factory()->create();
    $reservation = paidReservation($owner);
    $this->stripe->addSession($reservation->stripe_checkout_session_id);

    $this->actingAs($owner)->delete("/reservations/{$reservation->id}")
        ->assertRedirect(route('reservations.index'))
        ->assertSessionHas('status', 'reservation-cancelled-refunded');

    $reservation->refresh();
    expect($reservation->status)->toBe('cancelled');
    expect($reservation->payment_status)->toBe('refunded');
    expect($reservation->refund_requested_cents)->toBe(3000);
    expect($reservation->refunded_cents)->toBe(3000);
    expect($reservation->refunded_at)->not->toBeNull();
    expect($this->stripe->refundRequests)->toHaveCount(1);
    expect($this->stripe->refundRequests[0]['key'])->toBe("refund-reservations-{$reservation->id}-3000");
});

test('a Stripe outage keeps the refund owed instead of losing it, and the retry command finishes it', function () {
    $owner = User::factory()->create();
    $reservation = paidReservation($owner);
    $this->stripe->addSession($reservation->stripe_checkout_session_id);
    $this->stripe->refundFailure = 'network';

    $this->actingAs($owner)->delete("/reservations/{$reservation->id}")
        ->assertSessionHas('status', 'reservation-cancelled-refund-pending');

    $reservation->refresh();
    expect($reservation->status)->toBe('cancelled');
    expect($reservation->payment_status)->toBe('refund_failed');
    expect($reservation->refund_requested_cents)->toBe(3000);
    expect($reservation->refunded_cents)->toBe(0);

    // Stripe recovers; the command picks the row up on its next run.
    $this->stripe->refundFailure = null;
    $this->travel(10)->minutes();

    $this->artisan('payments:retry-refunds')->assertSuccessful();

    $reservation->refresh();
    expect($reservation->payment_status)->toBe('refunded');
    expect($reservation->refunded_cents)->toBe(3000);
});

test('the retry command keeps failing loudly while Stripe is still down', function () {
    $owner = User::factory()->create();
    $reservation = paidReservation($owner);
    $this->stripe->addSession($reservation->stripe_checkout_session_id);
    $this->stripe->refundFailure = 'network';
    $this->actingAs($owner)->delete("/reservations/{$reservation->id}");

    $this->travel(10)->minutes();

    $this->artisan('payments:retry-refunds')->assertFailed();
    expect($reservation->fresh()->payment_status)->toBe('refund_failed');
});

test('the retry command leaves a refund alone that a request is working on right now', function () {
    $owner = User::factory()->create();
    $reservation = paidReservation($owner);
    $this->stripe->addSession($reservation->stripe_checkout_session_id);
    $this->stripe->refundFailure = 'network';
    $this->actingAs($owner)->delete("/reservations/{$reservation->id}");
    $this->stripe->refundFailure = null;
    $this->stripe->refundRequests = [];

    $this->artisan('payments:retry-refunds')->assertSuccessful();

    expect($this->stripe->refundRequests)->toBeEmpty();
});

test('a refund Stripe says already happened is recorded as refunded', function () {
    $owner = User::factory()->create();
    $reservation = paidReservation($owner);
    $this->stripe->addSession($reservation->stripe_checkout_session_id);
    $this->stripe->refundFailure = 'already_refunded';

    $this->actingAs($owner)->delete("/reservations/{$reservation->id}")
        ->assertSessionHas('status', 'reservation-cancelled-refunded');

    expect($reservation->fresh()->payment_status)->toBe('refunded');
    expect($reservation->fresh()->refunded_cents)->toBe(3000);
});

test('refunding twice never sends the money twice', function () {
    $reservation = paidReservation(User::factory()->create());
    $this->stripe->addSession($reservation->stripe_checkout_session_id);

    app(Refunds::class)->refundInFull($reservation);
    app(Refunds::class)->refundInFull($reservation->fresh());
    app(Refunds::class)->settle($reservation->fresh());

    expect($this->stripe->refundRequests)->toHaveCount(1);
    expect($reservation->fresh()->refunded_cents)->toBe(3000);
});

test('cancelling too close to the start frees the slot but keeps the payment, still counted as paid', function () {
    $owner = User::factory()->create();
    $reservation = paidReservation($owner, [
        'starts_at' => now()->addHours(5),
        'ends_at' => now()->addHours(6),
    ]);
    $this->stripe->addSession($reservation->stripe_checkout_session_id);

    $this->actingAs($owner)->delete("/reservations/{$reservation->id}")
        ->assertSessionHas('status', 'reservation-cancelled-no-refund');

    $reservation->refresh();
    expect($reservation->status)->toBe('cancelled');
    expect($reservation->payment_status)->toBe('paid');
    expect($reservation->refund_requested_cents)->toBeNull();
    expect($this->stripe->refundRequests)->toBeEmpty();
});

test('cancelling twice does not refund twice', function () {
    $owner = User::factory()->create();
    $reservation = paidReservation($owner);
    $this->stripe->addSession($reservation->stripe_checkout_session_id);

    $this->actingAs($owner)->delete("/reservations/{$reservation->id}");
    $this->actingAs($owner)->delete("/reservations/{$reservation->id}");

    expect($this->stripe->refundRequests)->toHaveCount(1);
});

test('an admin cancelling an upcoming paid reservation refunds it regardless of the cutoff', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $reservation = paidReservation(User::factory()->create(), [
        'starts_at' => now()->addHours(2),
        'ends_at' => now()->addHours(3),
    ]);
    $this->stripe->addSession($reservation->stripe_checkout_session_id);

    $this->actingAs($admin);
    Livewire::test(ListReservations::class)->callTableAction('cancel', $reservation);

    $reservation->refresh();
    expect($reservation->status)->toBe('cancelled');
    expect($reservation->payment_status)->toBe('refunded');
});

test('an admin refunding a pass revokes it and records the refund', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $purchase = paidPurchase('cs_pass_refund');
    $this->stripe->addSession('cs_pass_refund');

    $this->actingAs($admin);
    Livewire::test(ListPurchases::class)->callTableAction('refund', $purchase);

    $purchase->refresh();
    expect($purchase->status)->toBe('cancelled');
    expect($purchase->payment_status)->toBe('refunded');
    expect($purchase->refunded_cents)->toBe(1000);
    expect($purchase->isCurrentlyUsable())->toBeFalse();
});

test('an admin refund that Stripe does not confirm still revokes the pass and stays owed', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $purchase = paidPurchase('cs_pass_refund2');
    $this->stripe->addSession('cs_pass_refund2');
    $this->stripe->refundFailure = 'network';

    $this->actingAs($admin);
    Livewire::test(ListPurchases::class)->callTableAction('refund', $purchase);

    $purchase->refresh();
    expect($purchase->status)->toBe('cancelled');
    expect($purchase->payment_status)->toBe('refund_failed');
    expect($purchase->outstandingRefundCents())->toBe(1000);
});

test('refunding a row nobody paid for through Stripe reports nothing to refund', function () {
    $reservation = makeReservation(User::factory()->create(), now()->addDay(), now()->addDay()->addHour());

    expect(app(Refunds::class)->refundInFull($reservation)->name)->toBe('NothingToRefund');
});
