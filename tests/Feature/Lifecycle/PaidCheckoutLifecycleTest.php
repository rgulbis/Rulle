<?php

use App\Filament\Widgets\RevenueStats;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\SubscriptionType;
use App\Models\User;
use App\Support\Payments\StripeGateway;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Tests\Support\FakeStripeGateway;

/*
| Whole journeys, driven through the real routes the way a rider and Stripe
| drive them: start a checkout, pay at Stripe, and (this is the point) never
| come back to the site, so that only the webhook ever reports the payment.
| The unit-sized cases for each step live next to the code they cover
| (CheckoutFulfillmentTest, RefundTest, RevenueTest); these check that the
| steps still add up to a rider who got what they paid for.
*/

function lifecycleWebhook(string $type, array $object): TestResponse
{
    // No webhook secret -> signature verification is skipped for the request.
    config(['cashier.webhook.secret' => null]);

    return test()->postJson('/stripe/webhook', ['type' => $type, 'data' => ['object' => $object]]);
}

/** Stripe telling us a Checkout session was paid. */
function lifecycleCheckoutPaid(string $sessionId): TestResponse
{
    return lifecycleWebhook('checkout.session.completed', [
        'id' => $sessionId,
        'object' => 'checkout.session',
        'status' => 'complete',
        'payment_status' => 'paid',
        'payment_intent' => 'pi_'.$sessionId,
    ]);
}

function lifecycleScan(User $staff, User $rider, string $mode): TestResponse
{
    return test()->actingAs($staff)->postJson('/staff/scan', ['code' => qrTokenFor($rider), 'mode' => $mode]);
}

function lifecyclePlan(array $attributes = []): SubscriptionType
{
    $plan = makeSubscriptionType(array_merge(['name' => 'Plan', 'active' => true], $attributes));
    $plan->forceFill(['stripe_product_id' => 'prod_lifecycle', 'stripe_price_id' => 'price_lifecycle'])->save();

    return $plan;
}

function lifecycleRevenueStat(string $label): string
{
    $html = Livewire::test(RevenueStats::class)->html();
    preg_match('/'.preg_quote($label, '/').'\s*<\/span>.*?fi-wi-stats-overview-stat-value">\s*([\d.,]+ €)/s', $html, $matches);

    return $matches[1] ?? "(no stat named {$label})";
}

// --- Paid at Stripe, the browser never returns ------------------------------

test('a pass paid at Stripe is honoured at the gate though the rider never came back to the site', function () {
    $stripe = fakeStripe();
    $rider = User::factory()->create();
    $staff = User::factory()->create(['role' => 'employee']);
    $pass = lifecyclePlan(['visit_limit' => 2]);

    $this->actingAs($rider)->post("/subscriptions/{$pass->id}/checkout")
        ->assertRedirect('https://checkout.test/cs_fake_1');

    // Started but not paid: the pass is not worth anything yet.
    expect(Purchase::sole())->status->toBe('pending')->payment_status->toBe('unpaid');
    lifecycleScan($staff, $rider, 'entry')->assertForbidden();

    // The rider pays and closes the tab. /subscriptions/success is never opened.
    lifecycleCheckoutPaid('cs_fake_1')->assertOk();

    expect(Purchase::sole())->status->toBe('active')->payment_status->toBe('paid')->visits_remaining->toBe(2);

    // Both visits work, a third does not.
    lifecycleScan($staff, $rider, 'entry')->assertOk();
    lifecycleScan($staff, $rider, 'exit')->assertOk();
    lifecycleScan($staff, $rider, 'entry')->assertOk();
    lifecycleScan($staff, $rider, 'exit')->assertOk();
    lifecycleScan($staff, $rider, 'entry')->assertForbidden();

    expect(Purchase::sole())->status->toBe('used_up')->visits_remaining->toBe(0);
    expect($stripe->refundRequests)->toBeEmpty();
});

test('a reservation paid at Stripe is booked, and its group chat opens, though the rider never came back', function () {
    $stripe = fakeStripe();
    makeReservationSettings();
    $owner = User::factory()->create();
    $startsAt = now()->addDay()->setTime(12, 0);

    $this->actingAs($owner)->post('/reservations', [
        'starts_at' => $startsAt->toDateTimeString(),
        'duration_minutes' => 60,
        'group_size' => 3,
    ])->assertRedirect('https://checkout.test/cs_fake_1');

    $reservation = Reservation::sole();
    expect($reservation)->status->toBe('pending')->payment_status->toBe('unpaid');
    // Nothing to talk about in a group that has not been paid for.
    $this->actingAs($owner)->get("/reservations/{$reservation->id}/chat")->assertForbidden();

    lifecycleCheckoutPaid('cs_fake_1')->assertOk();

    expect($reservation->fresh())->status->toBe('active')->payment_status->toBe('paid');
    $this->actingAs($owner)->get("/reservations/{$reservation->id}/chat")->assertOk();
    $this->actingAs($owner)->get('/reservations')->assertInertia(fn ($page) => $page
        ->where('mine.0.id', $reservation->id)
        ->where('mine.0.status', 'active')
    );
    expect($stripe->refundRequests)->toBeEmpty();
});

test('a subscription paid at Stripe lets the rider in though the success page was never opened', function () {
    fakeStripe();
    $rider = User::factory()->create(['stripe_id' => 'cus_lifecycle']);
    $staff = User::factory()->create(['role' => 'employee']);

    lifecycleScan($staff, $rider, 'entry')->assertForbidden();

    // For a recurring plan the subscription event is what records it (the
    // checkout.session.completed one is for passes and reservations).
    lifecycleWebhook('customer.subscription.created', [
        'id' => 'sub_lifecycle',
        'customer' => 'cus_lifecycle',
        'status' => 'active',
        'metadata' => [],
        'items' => ['data' => [[
            'id' => 'si_lifecycle',
            'quantity' => 1,
            'price' => ['id' => 'price_lifecycle', 'product' => 'prod_lifecycle', 'unit_amount' => 2000],
        ]]],
    ])->assertOk();
    lifecycleCheckoutPaid('cs_not_ours')->assertOk();

    expect($rider->fresh()->subscribed('default'))->toBeTrue();
    lifecycleScan($staff, $rider, 'entry')->assertOk();
    lifecycleScan($staff, $rider, 'exit')->assertOk();
    // A subscription is not a counted pass: it can come back as often as it likes.
    lifecycleScan($staff, $rider, 'entry')->assertOk();
});

// --- Revenue follows the money ---------------------------------------------

test('revenue and the ledger follow a reservation through payment and cancellation, with and without a refund', function () {
    $stripe = fakeStripe();
    makeReservationSettings(['cancellation_cutoff_hours' => 24]);

    // Booked and paid through the webhook, like every real reservation.
    $paid = function (string $session, int $priceCents, DateTimeInterface $start) use ($stripe) {
        $owner = User::factory()->create();
        $reservation = makeReservation($owner, now()->setTimestamp($start->getTimestamp()), now()->setTimestamp($start->getTimestamp())->addHour(), [
            'status' => 'pending',
            'price_cents' => $priceCents,
            'stripe_checkout_session_id' => $session,
        ]);
        $stripe->addSession($session);
        lifecycleCheckoutPaid($session)->assertOk();

        return [$owner, $reservation->fresh()];
    };

    [$keptOwner, $kept] = $paid('cs_kept', 1200, now()->addDays(4));
    [$earlyOwner, $early] = $paid('cs_early', 3000, now()->addDays(3));
    [$lateOwner, $late] = $paid('cs_late', 4500, now()->addHours(5));

    $this->actingAs($earlyOwner)->delete("/reservations/{$early->id}")->assertSessionHas('status', 'reservation-cancelled-refunded');
    $this->actingAs($lateOwner)->delete("/reservations/{$late->id}")->assertSessionHas('status', 'reservation-cancelled-no-refund');

    // Only the early cancellation sent money back.
    expect($stripe->refundRequests)->toHaveCount(1)->and($stripe->refundRequests[0]['amount'])->toBe(3000);
    expect($kept->fresh())->status->toBe('active')->payment_status->toBe('paid');
    expect($late->fresh())->status->toBe('cancelled')->payment_status->toBe('paid')->refunded_cents->toBe(0);
    expect($early->fresh())->status->toBe('cancelled')->payment_status->toBe('refunded')->refunded_cents->toBe(3000);

    // The ledger shows each of them for what it is...
    $ledger = Payment::where('type', 'reservation')->get()->keyBy('user_id');
    expect($ledger[$lateOwner->id])->amount_cents->toBe(4500)->payment_status->toBe('paid')->refunded_cents->toBe(0);
    expect($ledger[$earlyOwner->id])->amount_cents->toBe(3000)->payment_status->toBe('refunded')->refunded_cents->toBe(3000);

    // ...and revenue is what is left: the kept booking and the one cancelled
    // too late for a refund (12.00 + 45.00), not the one that was given back.
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    expect(lifecycleRevenueStat('Reservations'))->toBe('57.00 €');
    expect(lifecycleRevenueStat('Refunded'))->toBe('30.00 €');
});

// --- A foreign session_id gets nothing --------------------------------------

/**
 * A Stripe that remembers every session it was asked about, and that - like
 * the real one - answers 404 for an id it has never heard of.
 */
function lifecycleStripeThatCounts(): FakeStripeGateway
{
    $stripe = new class extends FakeStripeGateway
    {
        /** @var list<string> */
        public array $lookups = [];

        public function retrieveCheckoutSession(string $sessionId): Session
        {
            $this->lookups[] = $sessionId;

            return $this->sessions[$sessionId]
                ?? throw InvalidRequestException::factory("No such checkout.session: {$sessionId}", 404, null, null, null, 'resource_missing');
        }
    };
    app()->instance(StripeGateway::class, $stripe);

    return $stripe;
}

test('someone else\'s or an invented session_id on the reservation success page changes nothing and says so', function () {
    $stripe = lifecycleStripeThatCounts();
    $stripe->addSession('cs_victim');
    $victim = User::factory()->create();
    $attacker = User::factory()->create();
    $reservation = makeReservation($victim, now()->addDays(3), now()->addDays(3)->addHour(), [
        'status' => 'pending',
        'stripe_checkout_session_id' => 'cs_victim',
    ]);

    foreach (['cs_victim', 'cs_never_existed', '', '../cs_victim', 'cs_victim%00'] as $sessionId) {
        $this->actingAs($attacker)->get('/reservations/success?'.http_build_query(['session_id' => $sessionId]))
            ->assertRedirect(route('reservations.index'))
            ->assertSessionHas('status', 'reservation-incomplete');
    }
    // An array instead of a string, and no parameter at all.
    $this->actingAs($attacker)->get('/reservations/success?session_id[]=cs_victim')->assertSessionHas('status', 'reservation-incomplete');
    $this->actingAs($attacker)->get('/reservations/success')->assertSessionHas('status', 'reservation-incomplete');

    // Nothing about the victim's booking moved, and nothing was asked of Stripe on the attacker's behalf.
    expect($reservation->fresh())->status->toBe('pending')->payment_status->toBe('unpaid');
    expect($stripe->lookups)->toBeEmpty()->and($stripe->refundRequests)->toBeEmpty();

    // The victim's own payment still goes through afterwards.
    lifecycleCheckoutPaid('cs_victim')->assertOk();
    expect($reservation->fresh()->status)->toBe('active');
});

test('someone else\'s or an invented session_id on the subscriptions success page changes nothing and says so', function () {
    $stripe = lifecycleStripeThatCounts();
    $stripe->addSession('cs_victim_pass');
    $stripe->addSession('cs_victim_subscription', ['customer' => 'cus_victim', 'mode' => 'subscription']);
    $victim = User::factory()->create(['stripe_id' => 'cus_victim']);
    $attacker = User::factory()->create(['stripe_id' => 'cus_attacker']);
    $purchase = Purchase::create([
        'user_id' => $victim->id,
        'subscription_type_id' => makeSubscriptionType(['visit_limit' => 5])->id,
        'stripe_checkout_session_id' => 'cs_victim_pass',
        'price_cents' => 1000,
        'status' => 'pending',
    ]);

    // Real Stripe answers 404 to an id it has never issued; that must read as
    // "not complete", not as a server error.
    foreach (['cs_victim_pass', 'cs_victim_subscription', 'cs_never_existed', 'cs_victim_pass%00'] as $sessionId) {
        $this->actingAs($attacker)->get('/subscriptions/success?'.http_build_query(['session_id' => $sessionId]))
            ->assertRedirect(route('subscriptions.index'))
            ->assertSessionHas('status', 'purchase-incomplete');
    }
    $this->actingAs($attacker)->get('/subscriptions/success?session_id[]=cs_victim_pass')->assertSessionHas('status', 'purchase-incomplete');

    expect($purchase->fresh())->status->toBe('pending')->payment_status->toBe('unpaid')->visits_remaining->toBeNull();
    expect($stripe->refundRequests)->toBeEmpty();

    // The right owner gets their answer.
    $this->actingAs($victim)->get('/subscriptions/success?session_id=cs_victim_subscription')->assertSessionHas('status', 'purchase-complete');
    $this->actingAs($victim)->get('/subscriptions/success?session_id=cs_victim_pass')->assertSessionHas('status', 'purchase-complete');
    expect($purchase->fresh())->status->toBe('active')->visits_remaining->toBe(5);
});

test('the success pages are not for guests', function () {
    $this->get('/reservations/success?session_id=cs_any')->assertRedirect('/login');
    $this->get('/subscriptions/success?session_id=cs_any')->assertRedirect('/login');
});

test('a rider whose own payment is real but whose Stripe lookup fails is told it is not complete yet, not shown an error', function () {
    $stripe = new class extends FakeStripeGateway
    {
        public function retrieveCheckoutSession(string $sessionId): Session
        {
            throw new ApiConnectionException('Could not connect to Stripe.');
        }
    };
    app()->instance(StripeGateway::class, $stripe);

    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDays(3), now()->addDays(3)->addHour(), [
        'status' => 'pending',
        'stripe_checkout_session_id' => 'cs_mine',
    ]);

    $this->actingAs($owner)->get('/reservations/success?session_id=cs_mine')
        ->assertRedirect(route('reservations.index'))
        ->assertSessionHas('status', 'reservation-incomplete');

    // The webhook does the real work once Stripe is reachable again.
    lifecycleCheckoutPaid('cs_mine')->assertOk();
    expect($reservation->fresh()->status)->toBe('active');
});
