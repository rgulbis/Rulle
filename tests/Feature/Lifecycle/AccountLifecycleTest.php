<?php

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Support\Accounts\AccountClosure;
use App\Support\Payments\StripeGateway;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Stripe\Exception\InvalidRequestException;
use Tests\Support\FakeStripeGateway;

/*
| The ends of an account's life, driven through the real routes: signing up
| with an address that is not plain ASCII, and being closed while Stripe is
| still billing.
*/

// --- The Unicode email cycle -------------------------------------------------

test('someone who registers with a Unicode email can sign out and back in under either spelling', function () {
    // A browser only converts a Unicode domain in the address bar, so a form
    // field sends exactly what was typed. The account is stored in the
    // ASCII (punycode) form login looks it up by.
    $unicode = 'rider@rullē.lv';
    $ascii = 'rider@xn--rull-eva.lv';

    $this->post('/register', [
        'name' => 'Unicode Rider',
        'email' => $unicode,
        'password' => 'C0rrect!Horse42',
        'password_confirmation' => 'C0rrect!Horse42',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    expect(User::sole()->email)->toBe($ascii);

    $this->post('/logout')->assertRedirect('/login');
    $this->assertGuest();

    // Typing it the way it was typed at registration...
    $this->post('/login', ['email' => $unicode, 'password' => 'C0rrect!Horse42'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs(User::sole());

    $this->post('/logout');
    $this->assertGuest();

    // ...or the way it is stored.
    $this->post('/login', ['email' => $ascii, 'password' => 'C0rrect!Horse42'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs(User::sole());
    $this->post('/logout');

    // Still one address: neither spelling can be registered a second time.
    // (Registration, login and password reset share one 5-a-minute limit per
    // visitor, which is why this stops here.)
    foreach ([$unicode, $ascii] as $spelling) {
        $this->post('/register', [
            'name' => 'Someone Else '.md5($spelling),
            'email' => $spelling,
            'password' => 'C0rrect!Horse42',
            'password_confirmation' => 'C0rrect!Horse42',
        ])->assertSessionHasErrors('email');
    }
    expect(User::count())->toBe(1);
});

test('a password reset asked for with the Unicode spelling reaches the same account', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'rider@xn--rull-eva.lv']);

    $this->post('/forgot-password', ['email' => 'rider@rullē.lv']);

    Notification::assertSentTo($user, ResetPassword::class);
});

// --- Closing an account that is still being billed --------------------------

function lifecycleSubscriptionEvent(string $type, string $subscriptionId, string $status = 'active'): array
{
    return [
        'type' => $type,
        'data' => ['object' => array_filter([
            'id' => $subscriptionId,
            'customer' => 'cus_closing',
            'status' => $status,
            'cancel_at_period_end' => false,
            // What Stripe sends for a subscription that has been cancelled.
            'canceled_at' => $status === 'canceled' ? now()->getTimestamp() : null,
            'metadata' => [],
            'items' => ['data' => [[
                'id' => 'si_'.$subscriptionId,
                'quantity' => 1,
                'price' => ['id' => 'price_test', 'product' => 'prod_test', 'unit_amount' => 2000],
            ]]],
        ], fn ($value) => $value !== null)],
    ];
}

test('closing an account with a running subscription ends it at Stripe, and Stripe\'s later events cannot bring it back', function () {
    config(['cashier.webhook.secret' => null]);
    $stripe = fakeStripe();
    $staff = User::factory()->create(['role' => 'employee']);
    $admin = User::factory()->create(['role' => 'admin']);
    $rider = User::factory()->create(['name' => 'Closing Rider', 'email' => 'closing@example.com', 'stripe_id' => 'cus_closing']);

    $this->postJson('/stripe/webhook', lifecycleSubscriptionEvent('customer.subscription.created', 'sub_closing'))->assertOk();
    expect($rider->fresh()->subscribed('default'))->toBeTrue();
    $this->actingAs($staff)->postJson('/staff/scan', ['code' => qrTokenFor($rider), 'mode' => 'entry'])->assertOk();
    $staleCode = qrTokenFor($rider);

    // The admin closes the account from the panel.
    $this->actingAs($admin);
    Livewire::test(ListUsers::class)->callTableAction('delete', $rider)->assertNotified('Account closed');

    expect($stripe->cancelledSubscriptions)->toBe(['sub_closing']);
    expect(User::find($rider->id))->toBeNull();
    $closed = User::withTrashed()->findOrFail($rider->id);
    expect($closed->email)->not->toBe('closing@example.com')->and($closed->trashed())->toBeTrue();
    $subscription = DB::table('subscriptions')->where('stripe_id', 'sub_closing')->first();
    expect($subscription->stripe_status)->toBe('canceled')->and($subscription->ends_at)->not->toBeNull();

    // They cannot sign in any more, and the code they had open no longer opens the gate.
    $this->post('/logout');
    $this->post('/login', ['email' => 'closing@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
    $this->assertGuest();
    $this->actingAs($staff)->postJson('/staff/scan', ['code' => $staleCode, 'mode' => 'exit'])->assertNotFound();

    // Stripe is still telling us things about the customer it knew: its own
    // confirmation of the cancellation, and a replay of the original event.
    // None of it may fail, and none of it may change what was recorded.
    $this->postJson('/stripe/webhook', lifecycleSubscriptionEvent('customer.subscription.updated', 'sub_closing', 'canceled'))->assertOk();
    $this->postJson('/stripe/webhook', lifecycleSubscriptionEvent('customer.subscription.deleted', 'sub_closing', 'canceled'))->assertOk();
    $this->postJson('/stripe/webhook', lifecycleSubscriptionEvent('customer.subscription.created', 'sub_closing'))->assertOk();

    expect(DB::table('subscriptions')->where('user_id', $rider->id)->count())->toBe(1);
    expect(DB::table('subscriptions')->where('user_id', $rider->id)->whereNull('ends_at')->count())->toBe(0);
    expect($stripe->cancelledSubscriptions)->toBe(['sub_closing']);

    // A checkout the rider left open in another tab, paid after the account
    // was closed: Stripe now bills a customer nobody can sign in as. It is
    // not recorded, it is stopped at Stripe, and the owner is told loudly
    // because its first payment has to be refunded by hand.
    Log::spy();
    $this->postJson('/stripe/webhook', lifecycleSubscriptionEvent('customer.subscription.created', 'sub_after_closing'))->assertOk();
    // Stripe re-delivering that event changes nothing, and does not fail.
    $this->postJson('/stripe/webhook', lifecycleSubscriptionEvent('customer.subscription.created', 'sub_after_closing'))->assertOk();

    expect($stripe->cancelledSubscriptions)->toBe(['sub_closing', 'sub_after_closing', 'sub_after_closing']);
    expect(DB::table('subscriptions')->where('stripe_id', 'sub_after_closing')->exists())->toBeFalse();
    expect($closed->fresh()->subscribed('default'))->toBeFalse();
    Log::shouldHaveReceived('critical')->twice();
});

test('a subscription Stripe has already cancelled is refused quietly when its event is delivered again', function () {
    config(['cashier.webhook.secret' => null]);
    $stripe = new class extends FakeStripeGateway
    {
        public function cancelSubscriptionNow(string $stripeSubscriptionId): void
        {
            throw InvalidRequestException::factory('No such subscription', 404, null, null, null, 'resource_missing');
        }
    };
    app()->instance(StripeGateway::class, $stripe);
    Log::spy();

    $rider = User::factory()->create(['stripe_id' => 'cus_closing']);
    app(AccountClosure::class)->close($rider);

    $this->postJson('/stripe/webhook', lifecycleSubscriptionEvent('customer.subscription.created', 'sub_gone'))->assertOk();

    expect(DB::table('subscriptions')->where('stripe_id', 'sub_gone')->exists())->toBeFalse();
    Log::shouldHaveReceived('critical')->once();
});

test('the database will not let a user with a subscription be hard-deleted behind the application\'s back', function () {
    $rider = User::factory()->create(['stripe_id' => 'cus_hard']);
    $rider->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_hard', 'stripe_status' => 'active', 'stripe_price' => 'price_test', 'quantity' => 1]);

    expect(fn () => DB::table('users')->where('id', $rider->id)->delete())->toThrow(QueryException::class);
    expect(fn () => $rider->forceDelete())->toThrow(QueryException::class);

    expect(User::find($rider->id))->not->toBeNull();
});
