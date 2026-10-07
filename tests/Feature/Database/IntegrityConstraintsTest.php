<?php

use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function rawUserRow(array $attributes = []): array
{
    return array_merge([
        'name' => 'Person '.str()->random(6),
        'email' => str()->random(8).'@example.com',
        'password' => 'x',
        'role' => 'user',
        'qr_code' => (string) str()->uuid(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes);
}

// ------------------------------------------------------------ enum checks

test('the database refuses a role that does not exist', function () {
    expect(fn () => DB::table('users')->insert(rawUserRow(['role' => 'superuser'])))->toThrow(QueryException::class, 'invalid_users_role');

    $user = User::factory()->create();

    expect(fn () => DB::table('users')->where('id', $user->id)->update(['role' => 'god']))->toThrow(QueryException::class, 'invalid_users_role');
    expect($user->fresh()->role)->toBe('user');
});

test('the database refuses a status that does not exist on reservations, purchases and payments', function () {
    $user = User::factory()->create();
    $plan = makeSubscriptionType();
    $reservation = makeReservation($user, now()->addDay(), now()->addDay()->addHour());
    $purchase = Purchase::create([
        'user_id' => $user->id, 'subscription_type_id' => $plan->id, 'stripe_checkout_session_id' => 'cs_x',
        'price_cents' => 500, 'status' => 'active', 'payment_status' => 'paid',
    ]);

    expect(fn () => DB::table('reservations')->where('id', $reservation->id)->update(['status' => 'completed']))
        ->toThrow(QueryException::class, 'invalid_reservations_status');
    expect(fn () => DB::table('reservations')->where('id', $reservation->id)->update(['payment_status' => 'maybe']))
        ->toThrow(QueryException::class, 'invalid_reservations_payment_status');
    expect(fn () => DB::table('purchases')->where('id', $purchase->id)->update(['status' => 'refunded']))
        ->toThrow(QueryException::class, 'invalid_purchases_status');
    expect(fn () => DB::table('purchases')->where('id', $purchase->id)->update(['payment_status' => 'chargeback']))
        ->toThrow(QueryException::class, 'invalid_purchases_payment_status');
    expect(fn () => Reservation::create([
        'user_id' => $user->id, 'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(5)->addHour(),
        'group_size' => 3, 'price_cents' => 900, 'status' => 'booked',
    ]))->toThrow(QueryException::class, 'invalid_reservations_status');

    expect($reservation->fresh()->status)->toBe('active')
        ->and($purchase->fresh()->status)->toBe('active');
});

test('the database refuses a billing interval that does not exist', function () {
    $plan = makeSubscriptionType();

    expect(fn () => DB::table('subscription_types')->where('id', $plan->id)->update(['billing_interval' => 'weekly']))
        ->toThrow(QueryException::class, 'invalid_subscription_types_billing_interval');
});

test('every status the application writes is accepted', function () {
    $user = User::factory()->create();
    $plan = makeSubscriptionType();

    foreach (['pending', 'active', 'used_up', 'cancelled', 'abandoned'] as $status) {
        Purchase::create(['user_id' => $user->id, 'subscription_type_id' => $plan->id, 'stripe_checkout_session_id' => "cs_{$status}", 'status' => $status]);
    }

    foreach (['unpaid', 'paid', 'refunded', 'partially_refunded', 'refund_failed'] as $paymentStatus) {
        Purchase::create(['user_id' => $user->id, 'subscription_type_id' => $plan->id, 'stripe_checkout_session_id' => "cs_{$paymentStatus}", 'payment_status' => $paymentStatus]);
    }

    foreach (['user', 'employee', 'admin'] as $role) {
        expect(User::factory()->create(['role' => $role])->role)->toBe($role);
    }

    foreach (['one_time', 'month', 'year'] as $interval) {
        makeSubscriptionType(['billing_interval' => $interval]);
    }

    expect(Purchase::count())->toBe(10);
});

// ----------------------------------------------------------- unique names

test('two users cannot share a name, even past the application checks', function () {
    User::factory()->create(['name' => 'Jane']);

    expect(fn () => User::factory()->create(['name' => 'Jane']))->toThrow(UniqueConstraintViolationException::class);
});

test('a closed account still holds its (placeholder) name, so names stay unique', function () {
    $user = User::factory()->create(['name' => 'Jane']);
    $user->delete();

    expect(fn () => User::factory()->create(['name' => 'Jane']))->toThrow(UniqueConstraintViolationException::class);
});

test('two users cannot both be waiting for the same requested name', function () {
    User::factory()->create(['pending_name' => 'Shiny']);

    expect(fn () => User::factory()->create(['pending_name' => 'Shiny']))->toThrow(UniqueConstraintViolationException::class);

    User::factory()->count(2)->create(['pending_name' => null]);
});

// ----------------------------------------------------------- foreign keys

test('Cashier subscriptions must belong to a real user', function () {
    expect(fn () => DB::table('subscriptions')->insert([
        'user_id' => 9999, 'type' => 'default', 'stripe_id' => 'sub_orphan', 'stripe_status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'FOREIGN KEY');
});

test('subscription items must belong to a real subscription', function () {
    expect(fn () => DB::table('subscription_items')->insert([
        'subscription_id' => 9999, 'stripe_id' => 'si_orphan', 'stripe_product' => 'prod', 'stripe_price' => 'price',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'FOREIGN KEY');
});

test('a user with a subscription cannot be deleted for real, and neither can a subscription with items', function () {
    $user = User::factory()->create();
    $subscriptionId = DB::table('subscriptions')->insertGetId([
        'user_id' => $user->id, 'type' => 'default', 'stripe_id' => 'sub_a', 'stripe_status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('subscription_items')->insert([
        'subscription_id' => $subscriptionId, 'stripe_id' => 'si_a', 'stripe_product' => 'prod', 'stripe_price' => 'price',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('subscriptions')->where('id', $subscriptionId)->delete())->toThrow(QueryException::class, 'FOREIGN KEY');
    expect(fn () => DB::table('users')->where('id', $user->id)->delete())->toThrow(QueryException::class, 'FOREIGN KEY');
});

test('no foreign key in the schema still cascades', function () {
    $cascading = collect(['purchases', 'check_in_events', 'reservations', 'reservation_user', 'chat_messages', 'subscriptions', 'subscription_items'])
        ->flatMap(fn (string $table) => collect(Schema::getForeignKeys($table))
            ->filter(fn (array $key) => $key['on_delete'] === 'cascade')
            ->map(fn (array $key) => $table.'.'.implode(',', $key['columns'])))
        ->values()
        ->all();

    expect($cascading)->toBe([]);
});

test('a reservation with participants or chat cannot be deleted for real', function () {
    $owner = User::factory()->create();
    $guest = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    $reservation->participants()->attach($guest->id);

    expect(fn () => DB::table('reservations')->where('id', $reservation->id)->delete())->toThrow(QueryException::class, 'FOREIGN KEY');
});

test('the guards added earlier survived the table rebuilds', function () {
    $owner = User::factory()->create();
    makeReservation($owner, now()->addDay(), now()->addDay()->addHours(2));

    expect(fn () => makeReservation(User::factory()->create(), now()->addDay()->addHour(), now()->addDay()->addHours(3)))
        ->toThrow(QueryException::class, 'reservation_overlap');

    $user = User::factory()->create();
    $row = ['user_id' => $user->id, 'type' => 'default', 'stripe_status' => 'active', 'created_at' => now(), 'updated_at' => now()];
    DB::table('subscriptions')->insert($row + ['stripe_id' => 'sub_1']);

    expect(fn () => DB::table('subscriptions')->insert($row + ['stripe_id' => 'sub_2']))->toThrow(UniqueConstraintViolationException::class);

    expect(DB::table('payments')->count())->toBe(2);
});
