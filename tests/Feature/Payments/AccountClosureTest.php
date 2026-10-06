<?php

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\CheckInEvent;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\SubscriptionType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/*
| Deleting a customer must never rewrite financial history or leave Stripe
| charging someone whose account no longer exists.
*/

function admin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function giveSubscription(User $user, string $stripeId = 'sub_close', ?int $priceCents = 3500): int
{
    return DB::table('subscriptions')->insertGetId([
        'user_id' => $user->id,
        'type' => 'default',
        'stripe_id' => $stripeId,
        'stripe_status' => 'active',
        'stripe_price' => 'price_x',
        'price_cents' => $priceCents,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('closing an account cancels the Stripe subscription, anonymises the person and keeps their history', function () {
    $stripe = fakeStripe();
    $customer = User::factory()->create(['name' => 'Real Name', 'email' => 'real@example.com']);
    giveSubscription($customer);
    $purchase = Purchase::create([
        'user_id' => $customer->id,
        'subscription_type_id' => makeSubscriptionType()->id,
        'stripe_checkout_session_id' => 'cs_hist',
        'price_cents' => 500,
        'status' => 'used_up',
        'payment_status' => 'paid',
    ]);
    $reservation = makeReservation($customer, now()->subDays(2), now()->subDays(2)->addHour());
    CheckInEvent::create(['user_id' => $customer->id, 'checked_in' => true]);

    $customer->closeAccount(admin());

    expect($stripe->cancelledSubscriptions)->toBe(['sub_close']);
    expect(User::find($customer->id))->toBeNull();

    $closed = User::withTrashed()->find($customer->id);
    expect($closed)
        ->name->toBe("Deleted user {$customer->id}")
        ->email->toBe("deleted-{$customer->id}@deleted.invalid")
        ->trashed()->toBeTrue();

    // History is untouched and still points at the (anonymised) user.
    expect(Purchase::find($purchase->id)->user->name)->toBe("Deleted user {$customer->id}");
    expect($reservation->fresh())->not->toBeNull();
    expect(Payment::where('user_id', $customer->id)->count())->toBe(3);
    expect(DB::table('subscriptions')->where('stripe_id', 'sub_close')->value('stripe_status'))->toBe('canceled');
    // Checked in at the time -> checked out, so the live headcount isn't stuck.
    expect(CheckInEvent::where('user_id', $customer->id)->orderByDesc('id')->value('checked_in'))->toBeFalsy();
});

test('if Stripe cannot cancel the subscription, nothing is closed', function () {
    $stripe = fakeStripe();
    $stripe->failSubscriptionCancels = true;
    $customer = User::factory()->create(['name' => 'Still Here']);
    giveSubscription($customer);

    $result = UsersTable::closeAccounts(collect([$customer]));

    expect($result)->toBeFalse();
    expect($customer->fresh())->name->toBe('Still Here')->trashed()->toBeFalse();
});

test('a closed account can no longer log in', function () {
    fakeStripe();
    $customer = User::factory()->create(['email' => 'gone@example.com']);
    $customer->closeAccount(admin());

    $this->post('/login', ['email' => 'gone@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('an account cannot be closed by itself, as the last admin, or with upcoming paid reservations', function () {
    fakeStripe();
    $onlyAdmin = admin();
    expect(str_contains((string) $onlyAdmin->closureBlocker(User::factory()->create()), 'last administrator'))->toBeTrue();

    $second = admin();
    expect(str_contains((string) $second->closureBlocker($second), 'own account'))->toBeTrue();
    expect($second->closureBlocker($onlyAdmin))->toBeNull();

    $customer = User::factory()->create();
    makeReservation($customer, now()->addDays(2), now()->addDays(2)->addHour());
    expect(str_contains((string) $customer->closureBlocker($onlyAdmin), 'upcoming paid reservations'))->toBeTrue();
    expect(fn () => $customer->closeAccount($onlyAdmin))->toThrow(DomainException::class);
});

test('a user cannot be erased outright, in the app or in the database', function () {
    $customer = User::factory()->create();
    Purchase::create([
        'user_id' => $customer->id,
        'subscription_type_id' => makeSubscriptionType()->id,
        'stripe_checkout_session_id' => 'cs_keep',
        'price_cents' => 500,
        'status' => 'active',
        'payment_status' => 'paid',
    ]);

    expect(fn () => $customer->forceDelete())->toThrow(DomainException::class);
    expect(fn () => DB::table('users')->where('id', $customer->id)->delete())->toThrow(QueryException::class);
    expect(Purchase::count())->toBe(1);
});

test('the admin list closes accounts and reports the ones it cannot', function () {
    fakeStripe();
    $actor = admin();
    $closable = User::factory()->create();
    $blocked = User::factory()->create();
    makeReservation($blocked, now()->addDays(2), now()->addDays(2)->addHour());

    $this->actingAs($actor);

    Livewire::test(ListUsers::class)
        ->callTableBulkAction('closeAccounts', [$closable, $blocked]);

    expect(User::find($closable->id))->toBeNull();
    expect(User::find($blocked->id))->not->toBeNull();
});

test('an admin cannot demote themselves or the last admin', function () {
    $only = admin();
    $this->actingAs($only);

    Livewire::test(EditUser::class, ['record' => $only->id])
        ->fillForm(['role' => 'user'])
        ->call('save');

    expect($only->fresh()->role)->toBe('admin');
});

test('the admin user form enforces the same rules as public registration', function () {
    $this->actingAs(admin());
    User::factory()->create(['name' => 'Taken Name', 'email' => 'taken@example.com']);

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Taken Name', 'email' => 'taken@example.com', 'role' => 'user', 'password' => 'weak'])
        ->call('create')
        ->assertHasFormErrors(['name', 'email', 'password']);
});

test('an email with a unicode domain is stored in the form login looks it up by', function () {
    $this->actingAs(admin());

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Unicode Person', 'email' => 'jānis@rullē.lv', 'role' => 'user', 'password' => 'Str0ng!Passw0rd#xyz'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'jānis@xn--rull-eva.lv')->exists())->toBeTrue();
});

test('approving a pending name fails if someone else took it meanwhile', function () {
    $first = User::factory()->create(['pending_name' => 'Wanted']);
    User::factory()->create(['name' => 'Wanted']);

    expect(fn () => $first->approvePendingName())->toThrow(DomainException::class);
    expect($first->fresh())->pending_name->toBe('Wanted');
});

test('a subscription in the ledger keeps the price it was billed at when the plan is repriced', function () {
    $customer = User::factory()->create();
    $type = makeSubscriptionType(['billing_interval' => 'month', 'price_cents' => 3500]);
    DB::table('subscription_types')->where('id', $type->id)->update(['stripe_product_id' => 'prod_hist']);
    $subscriptionId = giveSubscription($customer, 'sub_hist', priceCents: 3500);
    DB::table('subscription_items')->insert([
        'subscription_id' => $subscriptionId, 'stripe_id' => 'si_h', 'stripe_product' => 'prod_hist',
        'stripe_price' => 'price_x', 'created_at' => now(), 'updated_at' => now(),
    ]);

    SubscriptionType::withoutEvents(fn () => $type->update(['price_cents' => 9900]));

    expect(Payment::find("subscription-{$subscriptionId}")->amount_cents)->toBe(3500);
});

test('the webhook records the amount a subscription is billed at', function () {
    config(['cashier.webhook.secret' => null]);
    $customer = User::factory()->create(['stripe_id' => 'cus_price']);
    $id = giveSubscription($customer, 'sub_webhook', priceCents: null);

    $this->postJson('/stripe/webhook', [
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'id' => 'sub_webhook',
            'customer' => 'cus_price',
            'status' => 'active',
            'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => 'price_x', 'unit_amount' => 4200, 'product' => 'prod_x'], 'quantity' => 1]]],
        ]],
    ])->assertOk();

    expect(DB::table('subscriptions')->where('id', $id)->value('price_cents'))->toBe(4200);
});

test('a plan that has been sold cannot be deleted or have its billing type changed', function () {
    $type = makeSubscriptionType(['billing_interval' => 'one_time']);
    Purchase::create([
        'user_id' => User::factory()->create()->id,
        'subscription_type_id' => $type->id,
        'stripe_checkout_session_id' => 'cs_sold',
        'price_cents' => 500,
        'status' => 'active',
        'payment_status' => 'paid',
    ]);

    expect($type->hasSales())->toBeTrue();
    expect(fn () => $type->delete())->toThrow(DomainException::class);
    expect(fn () => SubscriptionType::withoutEvents(fn () => null) ?? $type->update(['billing_interval' => 'month']))->toThrow(DomainException::class);
    expect(SubscriptionType::count())->toBe(1);
});

test('a plan nobody bought can be deleted', function () {
    expect(makeSubscriptionType()->delete())->toBeTrue();
});

test('a pass that would sell zero visits is not sellable', function () {
    expect(makeSubscriptionType(['visit_limit' => null, 'unlimited_entries' => false])->isSellable())->toBeFalse();
    expect(makeSubscriptionType(['visit_limit' => 0, 'unlimited_entries' => false])->isSellable())->toBeFalse();
    expect(makeSubscriptionType(['visit_limit' => 5, 'unlimited_entries' => false])->isSellable())->toBeTrue();
    expect(makeSubscriptionType(['visit_limit' => null, 'unlimited_entries' => true])->isSellable())->toBeTrue();
    expect(makeSubscriptionType(['price_cents' => 10, 'visit_limit' => 5])->isSellable())->toBeFalse();
});

test('a second live subscription for one customer is logged as critical', function () {
    config(['cashier.webhook.secret' => null]);
    Log::spy();
    $customer = User::factory()->create(['stripe_id' => 'cus_dup']);
    giveSubscription($customer, 'sub_first');
    giveSubscription($customer, 'sub_second');

    $this->postJson('/stripe/webhook', [
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'id' => 'sub_second', 'customer' => 'cus_dup', 'status' => 'active',
            'items' => ['data' => [['id' => 'si_x', 'price' => ['id' => 'price_x', 'unit_amount' => 1000, 'product' => 'prod_x'], 'quantity' => 1]]],
        ]],
    ])->assertOk();

    Log::shouldHaveReceived('critical')->once();
});
