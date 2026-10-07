<?php

use App\Events\ChatSlowModeActivated;
use App\Models\ChatMessage;
use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\SubscriptionType;
use App\Models\User;
use App\Support\ChatSlowMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

/*
| The guarantees that make the races in tests/Parallel impossible, checked
| one request at a time: the database triggers and indexes, the reuse of an
| unfinished checkout, the webhook's duplicate check and the chat limits.
| (Whether they hold under real simultaneity is what tests/Parallel is for.)
*/

function recurringPlan(): SubscriptionType
{
    $plan = makeSubscriptionType(['name' => 'Monthly', 'billing_interval' => 'month', 'active' => true]);
    $plan->forceFill(['stripe_product_id' => 'prod_test', 'stripe_price_id' => 'price_test'])->save();

    return $plan;
}

// --- SQLite transaction mode ----------------------------------------------

test('sqlite transactions take the write lock when they begin', function () {
    expect(config('database.connections.sqlite.transaction_mode'))->toBe('IMMEDIATE');
});

// --- Reservation overlap trigger ------------------------------------------

test('the database refuses a second active reservation for an overlapping time', function () {
    [$a, $b] = User::factory()->count(2)->create();
    makeReservation($a, now()->addDay()->setTime(12, 0), now()->addDay()->setTime(13, 0));

    expect(fn () => makeReservation($b, now()->addDay()->setTime(12, 30), now()->addDay()->setTime(13, 30)))
        ->toThrow(QueryException::class, 'reservation_overlap');
    expect(fn () => makeReservation($b, now()->addDay()->setTime(11, 0), now()->addDay()->setTime(14, 0)))
        ->toThrow(QueryException::class, 'reservation_overlap');

    expect(Reservation::count())->toBe(1);
});

test('back-to-back, pending and cancelled reservations may share a time with an active one', function () {
    [$a, $b] = User::factory()->count(2)->create();
    makeReservation($a, now()->addDay()->setTime(12, 0), now()->addDay()->setTime(13, 0));

    // Ends exactly when the other starts, and starts exactly when it ends.
    makeReservation($b, now()->addDay()->setTime(11, 0), now()->addDay()->setTime(12, 0));
    makeReservation($b, now()->addDay()->setTime(13, 0), now()->addDay()->setTime(14, 0));
    // Two people mid-checkout for the same slot is allowed; the first payment wins.
    makeReservation($b, now()->addDay()->setTime(12, 15), now()->addDay()->setTime(12, 45), ['status' => 'pending']);
    makeReservation($b, now()->addDay()->setTime(12, 15), now()->addDay()->setTime(12, 45), ['status' => 'cancelled']);

    expect(Reservation::count())->toBe(5);
});

test('a pending reservation cannot be switched to active over an active one', function () {
    [$a, $b] = User::factory()->count(2)->create();
    makeReservation($a, now()->addDay()->setTime(12, 0), now()->addDay()->setTime(13, 0));
    $pending = makeReservation($b, now()->addDay()->setTime(12, 30), now()->addDay()->setTime(13, 30), ['status' => 'pending']);

    expect(fn () => $pending->update(['status' => 'active']))
        ->toThrow(QueryException::class, 'reservation_overlap');
    expect($pending->fresh()->status)->toBe('pending');
});

test('moving an active reservation onto another active one is refused, moving it elsewhere is not', function () {
    [$a, $b] = User::factory()->count(2)->create();
    makeReservation($a, now()->addDay()->setTime(12, 0), now()->addDay()->setTime(13, 0));
    $mine = makeReservation($b, now()->addDay()->setTime(15, 0), now()->addDay()->setTime(16, 0));

    expect(fn () => $mine->update(['starts_at' => now()->addDay()->setTime(12, 30), 'ends_at' => now()->addDay()->setTime(13, 30)]))
        ->toThrow(QueryException::class, 'reservation_overlap');

    $mine->update(['starts_at' => now()->addDay()->setTime(17, 0), 'ends_at' => now()->addDay()->setTime(18, 0)]);
    expect($mine->fresh()->starts_at->hour)->toBe(17);
});

test('an active reservation can still be cancelled and have its payment state updated', function () {
    $a = User::factory()->create();
    $reservation = makeReservation($a, now()->addDay()->setTime(12, 0), now()->addDay()->setTime(13, 0));

    $reservation->update(['payment_status' => 'refunded']);
    $reservation->update(['status' => 'cancelled']);

    expect($reservation->fresh()->status)->toBe('cancelled');
});

test('booking over a paid slot through the form is turned away with a message', function () {
    makeReservationSettings();
    [$a, $b] = User::factory()->count(2)->create();
    $start = now()->addDay()->setTime(12, 0);
    makeReservation($a, $start, $start->addHour());

    $this->actingAs($b)->post('/reservations', [
        'starts_at' => $start->addMinutes(30)->toDateTimeString(),
        'duration_minutes' => 60,
        'group_size' => 3,
    ])->assertSessionHasErrors('starts_at');

    expect(Reservation::count())->toBe(1);
});

// --- Participant capacity -------------------------------------------------

test('inviting someone who already joined is a no-op even when the group is full', function () {
    $owner = User::factory()->create();
    [$one, $two] = User::factory()->count(2)->create();
    $reservation = makeReservation($owner, now()->addDay()->setTime(12, 0), now()->addDay()->setTime(13, 0), ['group_size' => 3]);

    foreach ([$one, $two] as $friend) {
        $this->actingAs($owner)->post("/reservations/{$reservation->id}/participants", ['user_id' => $friend->id])->assertSessionHasNoErrors();
        $this->actingAs($friend)->post("/reservations/{$reservation->id}/invitation/accept")->assertSessionHas('status', 'invitation-accepted');
    }

    $this->actingAs($owner)->post("/reservations/{$reservation->id}/participants", ['user_id' => $one->id])->assertSessionHasNoErrors();
    expect($reservation->participants()->count())->toBe(2);

    $third = User::factory()->create();
    $this->actingAs($owner)->post("/reservations/{$reservation->id}/participants", ['user_id' => $third->id])->assertSessionHasErrors('user_id');
    expect($reservation->participants()->count())->toBe(2);
});

// --- Subscription checkout ------------------------------------------------

test('asking to subscribe twice sends the customer back to the same open Stripe session', function () {
    $stripe = fakeStripe();
    $user = User::factory()->create();
    $plan = recurringPlan();

    $first = $this->actingAs($user)->post("/subscriptions/{$plan->id}/checkout");
    $second = $this->actingAs($user)->post("/subscriptions/{$plan->id}/checkout");

    expect($stripe->createdCheckouts)->toHaveCount(1);
    expect($second->headers->get('Location'))->toBe($first->headers->get('Location'));
});

test('once the earlier checkout session is finished or expired, subscribing opens a fresh one', function () {
    $stripe = fakeStripe();
    $user = User::factory()->create();
    $plan = recurringPlan();

    $this->actingAs($user)->post("/subscriptions/{$plan->id}/checkout");
    $stripe->sessions['cs_fake_1']->status = 'expired';
    $this->actingAs($user)->post("/subscriptions/{$plan->id}/checkout");

    expect($stripe->createdCheckouts)->toHaveCount(2);
});

test('different customers each get their own checkout session', function () {
    $stripe = fakeStripe();
    $plan = recurringPlan();

    $this->actingAs(User::factory()->create())->post("/subscriptions/{$plan->id}/checkout");
    $this->actingAs(User::factory()->create())->post("/subscriptions/{$plan->id}/checkout");

    expect($stripe->createdCheckouts)->toHaveCount(2);
});

test('buying a one-time pass twice reuses the pending purchase and its open session', function () {
    $stripe = fakeStripe();
    $user = User::factory()->create();
    $pass = makeSubscriptionType();
    $pass->forceFill(['stripe_product_id' => 'prod_pass', 'stripe_price_id' => 'price_pass'])->save();

    $this->actingAs($user)->post("/subscriptions/{$pass->id}/checkout");
    $this->actingAs($user)->post("/subscriptions/{$pass->id}/checkout");

    expect($stripe->createdCheckouts)->toHaveCount(1);
    expect(Purchase::where('user_id', $user->id)->count())->toBe(1);
});

test('someone who already has a live subscription still cannot start another', function () {
    $stripe = fakeStripe();
    $user = User::factory()->create();
    $plan = recurringPlan();
    $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_live', 'stripe_status' => 'active', 'stripe_price' => 'price_test', 'quantity' => 1]);

    $this->actingAs($user)->post("/subscriptions/{$plan->id}/checkout")->assertStatus(409);
    expect($stripe->createdCheckouts)->toBeEmpty();
});

// --- Webhook: one live subscription per customer --------------------------

function sendStripeWebhook(array $payload): TestResponse
{
    // No webhook secret -> signature verification is skipped for the request.
    config(['cashier.webhook.secret' => null]);

    return test()->postJson('/stripe/webhook', $payload);
}

function subscriptionCreatedPayload(User $user, string $subscriptionId, string $status = 'active'): array
{
    return [
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'id' => $subscriptionId,
            'customer' => $user->stripe_id,
            'status' => $status,
            'metadata' => [],
            'items' => ['data' => [[
                'id' => 'si_'.$subscriptionId,
                'quantity' => 1,
                'price' => ['id' => 'price_test', 'product' => 'prod_test', 'unit_amount' => 2000],
            ]]],
        ]],
    ];
}

test('the webhook records a customer\'s first subscription', function () {
    $stripe = fakeStripe();
    $user = User::factory()->create(['stripe_id' => 'cus_one']);

    sendStripeWebhook(subscriptionCreatedPayload($user, 'sub_first'))->assertOk();

    expect($user->subscriptions()->pluck('stripe_id')->all())->toBe(['sub_first']);
    expect(DB::table('subscriptions')->where('stripe_id', 'sub_first')->value('price_cents'))->toBe(2000);
    expect($stripe->cancelledSubscriptions)->toBeEmpty();
});

test('the webhook refuses to record a second live subscription and cancels it at Stripe', function () {
    $stripe = fakeStripe();
    Log::spy();
    $user = User::factory()->create(['stripe_id' => 'cus_two']);
    sendStripeWebhook(subscriptionCreatedPayload($user, 'sub_first'))->assertOk();

    sendStripeWebhook(subscriptionCreatedPayload($user, 'sub_second'))->assertOk();

    expect($user->subscriptions()->pluck('stripe_id')->all())->toBe(['sub_first']);
    expect($stripe->cancelledSubscriptions)->toBe(['sub_second']);
    Log::shouldHaveReceived('critical')->once();
});

test('a re-delivered webhook for the subscription already recorded is not treated as a duplicate', function () {
    $stripe = fakeStripe();
    $user = User::factory()->create(['stripe_id' => 'cus_redeliver']);

    sendStripeWebhook(subscriptionCreatedPayload($user, 'sub_first'))->assertOk();
    sendStripeWebhook(subscriptionCreatedPayload($user, 'sub_first'))->assertOk();

    expect($user->subscriptions()->count())->toBe(1);
    expect($stripe->cancelledSubscriptions)->toBeEmpty();
});

test('a customer whose earlier subscription is cancelled and over can subscribe again', function () {
    $stripe = fakeStripe();
    $user = User::factory()->create(['stripe_id' => 'cus_again']);
    $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_old', 'stripe_status' => 'canceled', 'ends_at' => now()->subDay(), 'quantity' => 1]);
    // Cancelled but still running to the end of the period: also not "live".
    $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_ending', 'stripe_status' => 'active', 'ends_at' => now()->addWeek(), 'quantity' => 1]);

    sendStripeWebhook(subscriptionCreatedPayload($user, 'sub_new'))->assertOk();

    expect($user->subscriptions()->where('stripe_id', 'sub_new')->exists())->toBeTrue();
    expect($stripe->cancelledSubscriptions)->toBeEmpty();
});

test('the database refuses two live subscriptions for one customer but allows history', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $live = fn (User $u, string $id, array $extra = []) => $u->subscriptions()->create(array_merge(
        ['type' => 'default', 'stripe_id' => $id, 'stripe_status' => 'active', 'quantity' => 1], $extra,
    ));

    $live($user, 'sub_1');
    $live($user, 'sub_ended', ['stripe_status' => 'canceled', 'ends_at' => now()->subDay()]);
    $live($user, 'sub_ending', ['ends_at' => now()->addDay()]);
    $live($other, 'sub_other');

    expect(fn () => $live($user, 'sub_2'))->toThrow(QueryException::class);
    expect(fn () => $live($user, 'sub_3', ['stripe_status' => 'past_due']))->toThrow(QueryException::class);
    // A different type of subscription is a different thing.
    $live($user, 'sub_addon', ['type' => 'addon']);

    expect($user->subscriptions()->count())->toBe(4);
});

// --- Chat -------------------------------------------------------------------

test('slow mode switches on once however many messages tip it over', function () {
    $author = User::factory()->create();
    foreach (range(1, 10) as $i) {
        ChatMessage::create(['user_id' => $author->id, 'body' => "message {$i}"]);
    }
    // The tenth message above already tipped it over; start from "off".
    Cache::forget('chat.slow_mode_until');
    Event::fake([ChatSlowModeActivated::class]);

    ChatSlowMode::evaluate();
    ChatSlowMode::evaluate();
    ChatSlowMode::evaluate();

    expect(ChatSlowMode::isActive())->toBeTrue();
    Event::assertDispatchedTimes(ChatSlowModeActivated::class, 1);
});

test('a request that finds another one already switching slow mode on leaves it to that one', function () {
    $author = User::factory()->create();
    foreach (range(1, 10) as $i) {
        ChatMessage::create(['user_id' => $author->id, 'body' => "message {$i}"]);
    }
    // The tenth message above already tipped it over; start from "off".
    Cache::forget('chat.slow_mode_until');
    Event::fake([ChatSlowModeActivated::class]);

    $other = Cache::lock('chat.slow_mode_until.evaluate', 5);
    expect($other->get())->toBeTrue();

    ChatSlowMode::evaluate();

    expect(ChatSlowMode::isActive())->toBeFalse();
    Event::assertNotDispatched(ChatSlowModeActivated::class);
    $other->release();
});

test('a customer in slow mode is still held to the cooldown through the locked path', function () {
    $user = User::factory()->create();
    Cache::put('chat.slow_mode_until', now()->getTimestamp() + 120, 180);

    $this->actingAs($user)->post('/chat', ['body' => 'first'])->assertSessionHasNoErrors();
    $this->actingAs($user)->post('/chat', ['body' => 'second'])->assertSessionHasErrors(['body', 'slow_mode_wait']);

    expect(ChatMessage::where('user_id', $user->id)->count())->toBe(1);
});

test('the global chat limits how fast one user can post', function () {
    // An employee, because twenty customer messages would trip slow mode first.
    $user = User::factory()->create(['role' => 'employee']);

    foreach (range(1, 20) as $i) {
        $this->actingAs($user)->post('/chat', ['body' => "message {$i}"])->assertSessionHasNoErrors();
    }

    $this->actingAs($user)->post('/chat', ['body' => 'one too many'])->assertStatus(429);
    expect(ChatMessage::count())->toBe(20);

    // Someone else is not affected by this user's limit.
    $this->actingAs(User::factory()->create())->post('/chat', ['body' => 'hello'])->assertSessionHasNoErrors();
});

test('reservation group chats limit how fast one user can post', function () {
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay()->setTime(12, 0), now()->addDay()->setTime(13, 0));

    foreach (range(1, 20) as $i) {
        $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat", ['body' => "message {$i}"])->assertSessionHasNoErrors();
    }

    $this->actingAs($owner)->post("/reservations/{$reservation->id}/chat", ['body' => 'one too many'])->assertStatus(429);
    expect(ChatMessage::where('reservation_id', $reservation->id)->count())->toBe(20);
});

test('the chat send limit is its own counter, separate from the reservation pages', function () {
    makeReservationSettings();
    $user = User::factory()->create();

    foreach (range(1, 20) as $i) {
        $this->actingAs($user)->post('/chat', ['body' => "message {$i}"]);
    }

    // The pages share a 30/min bucket with each other, not with chat.
    $this->actingAs($user)->get('/reservations')->assertOk();
    $this->actingAs($user)->get('/chat')->assertOk();
});
