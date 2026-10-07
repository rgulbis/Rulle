<?php

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\ChatMessage;
use App\Models\CheckInEvent;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Accounts\AccountClosure;
use App\Support\Accounts\AccountClosureBlocked;
use App\Support\Payments\StripeGateway;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Tests\Support\FakeStripeGateway;

function closableCustomer(array $attributes = []): User
{
    return User::factory()->create(array_merge(['name' => 'Jane Rider', 'email' => 'jane@example.com'], $attributes));
}

function addSubscription(User $user, string $status = 'active', array $attributes = []): int
{
    return DB::table('subscriptions')->insertGetId(array_merge([
        'user_id' => $user->id,
        'type' => 'default',
        'stripe_id' => 'sub_'.str()->random(10),
        'stripe_status' => $status,
        'stripe_price' => 'price_test',
        'price_cents' => 1500,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

test('closing an account soft-deletes it and replaces everything that identifies the person', function () {
    $stripe = fakeStripe();
    $user = closableCustomer(['pending_name' => 'Janey', 'chat_muted_until' => now()->addDay()]);
    $user->forceFill(['stripe_id' => 'cus_jane', 'pm_type' => 'visa', 'pm_last_four' => '4242'])->save();
    $oldQr = $user->qr_code;

    app(AccountClosure::class)->close($user);

    $closed = User::withTrashed()->find($user->id);

    expect($closed->trashed())->toBeTrue()
        ->and($closed->name)->toBe("Deleted user {$user->id}")
        ->and($closed->email)->toBe("deleted-{$user->id}@deleted.invalid")
        ->and($closed->pending_name)->toBeNull()
        ->and($closed->email_verified_at)->toBeNull()
        ->and($closed->qr_code)->not->toBe($oldQr)
        ->and($closed->chat_muted_until)->toBeNull()
        ->and($closed->pm_last_four)->toBeNull()
        ->and($closed->role)->toBe('user')
        ->and($closed->stripe_id)->toBe('cus_jane');

    expect(User::find($user->id))->toBeNull()
        ->and($stripe->anonymisedCustomers)->toBe([['customer' => 'cus_jane', 'name' => "Deleted user {$user->id}", 'email' => "deleted-{$user->id}@deleted.invalid"]]);
});

test('a closed account can no longer sign in, and its email can be registered again', function () {
    $user = closableCustomer();
    $this->post('/login', ['email' => 'jane@example.com', 'password' => 'password']);
    $this->assertAuthenticated();
    $this->post('/logout');

    app(AccountClosure::class)->close($user);

    $this->post('/login', ['email' => 'jane@example.com', 'password' => 'password']);
    $this->assertGuest();

    $this->post('/register', [
        'name' => 'Jane Rider',
        'email' => 'jane@example.com',
        'password' => 'Str0ng!Passw0rd#2026',
        'password_confirmation' => 'Str0ng!Passw0rd#2026',
    ])->assertSessionHasNoErrors();

    expect(User::where('email', 'jane@example.com')->count())->toBe(1)
        ->and(User::where('email', 'jane@example.com')->first()->id)->not->toBe($user->id);
});

test('a closed account is signed out everywhere and its password reset requests are dropped', function () {
    $user = closableCustomer();
    DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    DB::table('password_reset_tokens')->insert(['email' => 'jane@example.com', 'token' => 'x', 'created_at' => now()]);

    app(AccountClosure::class)->close($user);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->count())->toBe(0);
});

test('purchases, reservations, check-ins and payments are kept and still show who they belonged to', function () {
    $user = closableCustomer();
    $plan = makeSubscriptionType();
    $purchase = Purchase::create([
        'user_id' => $user->id, 'subscription_type_id' => $plan->id, 'stripe_checkout_session_id' => 'cs_1',
        'price_cents' => 500, 'status' => 'active', 'payment_status' => 'paid', 'visits_remaining' => 1,
    ]);
    $reservation = makeReservation($user, now()->subDays(3), now()->subDays(3)->addHour());
    CheckInEvent::create(['user_id' => $user->id, 'checked_in' => true]);
    CheckInEvent::create(['user_id' => $user->id, 'checked_in' => false]);

    app(AccountClosure::class)->close($user);

    expect(Purchase::find($purchase->id))->not->toBeNull()
        ->and(Reservation::find($reservation->id))->not->toBeNull()
        ->and(CheckInEvent::where('user_id', $user->id)->count())->toBe(2)
        ->and(Payment::where('user_id', $user->id)->count())->toBe(2)
        ->and($purchase->fresh()->user->name)->toBe("Deleted user {$user->id}")
        ->and($reservation->fresh()->user->trashed())->toBeTrue();
});

test('someone closed while inside the park is checked out', function () {
    $user = closableCustomer();
    CheckInEvent::create(['user_id' => $user->id, 'checked_in' => true]);

    app(AccountClosure::class)->close($user);

    expect($user->isCurrentlyCheckedIn())->toBeFalse()
        ->and(CheckInEvent::where('user_id', $user->id)->count())->toBe(2);
});

test('their chat messages and participant links are removed, and replies to them stop quoting', function () {
    $user = closableCustomer();
    $other = User::factory()->create();
    $owner = User::factory()->create();
    $gone = ChatMessage::create(['user_id' => $user->id, 'body' => 'my secret phone number']);
    $reply = ChatMessage::create(['user_id' => $other->id, 'body' => 'ok', 'reply_to_message_id' => $gone->id]);
    $reservation = makeReservation($owner, now()->addDays(2), now()->addDays(2)->addHour());
    $reservation->participants()->attach($user->id);

    app(AccountClosure::class)->close($user);

    expect(ChatMessage::find($gone->id))->toBeNull()
        ->and($reply->fresh()->reply_to_message_id)->toBeNull()
        ->and($reservation->participants()->count())->toBe(0);
});

test('a running subscription is cancelled at Stripe first, and locally', function () {
    $stripe = fakeStripe();
    $user = closableCustomer();
    $id = addSubscription($user, 'active', ['stripe_id' => 'sub_live']);
    addSubscription($user, 'canceled', ['stripe_id' => 'sub_old', 'ends_at' => now()->subMonth()]);

    app(AccountClosure::class)->close($user);

    expect($stripe->cancelledSubscriptions)->toBe(['sub_live'])
        ->and(DB::table('subscriptions')->where('id', $id)->value('stripe_status'))->toBe('canceled')
        ->and(DB::table('subscriptions')->where('id', $id)->value('ends_at'))->not->toBeNull();
});

test('if Stripe cannot cancel the subscription nothing about the account changes', function () {
    $stripe = new class extends FakeStripeGateway
    {
        public function cancelSubscriptionNow(string $stripeSubscriptionId): void
        {
            throw new ApiConnectionException('Could not connect to Stripe.');
        }
    };
    app()->instance(StripeGateway::class, $stripe);

    $user = closableCustomer();
    $id = addSubscription($user);

    expect(fn () => app(AccountClosure::class)->close($user))->toThrow(ApiConnectionException::class);

    $fresh = User::find($user->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->email)->toBe('jane@example.com')
        ->and(DB::table('subscriptions')->where('id', $id)->value('stripe_status'))->toBe('active');
});

test('a subscription Stripe no longer knows about does not stop the account from closing', function () {
    $stripe = new class extends FakeStripeGateway
    {
        public function cancelSubscriptionNow(string $stripeSubscriptionId): void
        {
            throw InvalidRequestException::factory('No such subscription', 404, null, null, null, 'resource_missing');
        }
    };
    app()->instance(StripeGateway::class, $stripe);

    $user = closableCustomer();
    addSubscription($user);

    app(AccountClosure::class)->close($user);

    expect(User::find($user->id))->toBeNull();
});

test('a failure to scrub the Stripe customer does not undo the closure', function () {
    $stripe = fakeStripe();
    $stripe->anonymiseFails = true;
    $user = closableCustomer();
    $user->forceFill(['stripe_id' => 'cus_jane'])->save();

    app(AccountClosure::class)->close($user);

    expect(User::withTrashed()->find($user->id)->trashed())->toBeTrue();
});

test('an account with an upcoming paid reservation cannot be closed', function () {
    fakeStripe();
    $user = closableCustomer();
    makeReservation($user, now()->addDays(2), now()->addDays(2)->addHour());

    $blocked = null;

    try {
        app(AccountClosure::class)->close($user);
    } catch (AccountClosureBlocked $e) {
        $blocked = $e;
    }

    expect($blocked?->getMessage())->toContain('1 upcoming paid reservation')
        ->and(User::find($user->id)->email)->toBe('jane@example.com');
});

test('past, cancelled and unpaid reservations do not block closing, and open checkouts are written off', function () {
    fakeStripe();
    $user = closableCustomer();
    makeReservation($user, now()->subDays(2), now()->subDays(2)->addHour());
    makeReservation($user, now()->addDays(2), now()->addDays(2)->addHour(), ['status' => 'cancelled', 'payment_status' => 'refunded']);
    $pending = makeReservation($user, now()->addDays(4), now()->addDays(4)->addHour(), ['status' => 'pending']);
    $purchase = Purchase::create([
        'user_id' => $user->id, 'subscription_type_id' => makeSubscriptionType()->id, 'stripe_checkout_session_id' => 'cs_open',
        'price_cents' => 500, 'status' => 'pending',
    ]);

    app(AccountClosure::class)->close($user);

    expect($pending->fresh()->status)->toBe('cancelled')
        ->and($purchase->fresh()->status)->toBe('abandoned');
});

test('nobody can close their own account, and the last admin cannot be closed', function () {
    fakeStripe();
    $onlyAdmin = User::factory()->create(['role' => 'admin']);
    $closure = app(AccountClosure::class);

    expect($closure->blockers($onlyAdmin, $onlyAdmin))->toHaveCount(2)
        ->and($closure->blockers($onlyAdmin, $onlyAdmin)[0])->toContain('your own account');

    $secondAdmin = User::factory()->create(['role' => 'admin']);

    // With a second admin, closing the first is fine (done by the second).
    expect($closure->blockers($onlyAdmin, $secondAdmin))->toBe([])
        ->and($closure->blockers($onlyAdmin, $onlyAdmin))->toHaveCount(1);

    $closure->close($onlyAdmin, $secondAdmin);

    // ...which makes the second one the last.
    expect(fn () => $closure->close($secondAdmin))->toThrow(AccountClosureBlocked::class, 'last admin');
});

test('the database refuses to hard-delete a user who has any history', function () {
    $user = closableCustomer();
    CheckInEvent::create(['user_id' => $user->id, 'checked_in' => true]);

    expect(fn () => DB::table('users')->where('id', $user->id)->delete())->toThrow(QueryException::class);
    expect(User::find($user->id))->not->toBeNull();
});

test('the admin panel closes an account from the list, with a reason when it cannot', function () {
    $stripe = fakeStripe();
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = closableCustomer();
    $busy = User::factory()->create();
    makeReservation($busy, now()->addDay(), now()->addDay()->addHour());

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->callTableAction('delete', $customer)
        ->assertNotified('Account closed');

    expect(User::find($customer->id))->toBeNull();

    Livewire::test(ListUsers::class)
        ->callTableAction('delete', $busy)
        ->assertNotified("{$busy->name}'s account can't be closed");

    expect(User::find($busy->id))->not->toBeNull();
});

test('the admin panel will not close the admin\'s own account', function () {
    fakeStripe();
    $admin = User::factory()->create(['role' => 'admin']);
    User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $admin->id])
        ->callAction('delete')
        ->assertNotified("{$admin->name}'s account can't be closed");

    expect(User::find($admin->id))->not->toBeNull();
});

test('there is no bulk delete for users', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin);

    expect(Livewire::test(ListUsers::class)->instance()->getTable()->getBulkActions())->toBeEmpty();
});
