<?php

use App\Models\Reservation;
use App\Models\ReservationSetting;
use App\Models\SubscriptionType;
use App\Models\User;
use App\Support\EntryToken;
use App\Support\Payments\StripeGateway;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Checkout\Session;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

// Every feature test gets a fake Stripe gateway by default, so nothing here
// can reach the real Stripe API with whatever test key a developer's .env has.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => fakeStripe())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

// Typed against the interface, not Illuminate\Support\Carbon: this app
// configures Date::use(CarbonImmutable::class) (see AppServiceProvider), so
// now()->addDay() etc. actually return Carbon\CarbonImmutable instances.
function makeReservation(User $owner, CarbonInterface $start, CarbonInterface $end, array $attributes = []): Reservation
{
    // An "active" reservation is a paid one, so it gets a payment to match.
    if (($attributes['status'] ?? 'active') === 'active') {
        $attributes += [
            'payment_status' => 'paid',
            'stripe_payment_intent_id' => 'pi_test_'.uniqid(),
            'paid_at' => now(),
        ];
    }

    return Reservation::create(array_merge([
        'user_id' => $owner->id,
        'starts_at' => $start,
        'ends_at' => $end,
        'group_size' => 3,
        'price_cents' => 3000,
        'status' => 'active',
    ], $attributes));
}

function makeReservationSettings(array $attributes = []): ReservationSetting
{
    return ReservationSetting::forceCreate(array_merge([
        'id' => 1,
        'price_cents_per_person_per_hour' => 500,
        'min_group_size' => 3,
        'max_group_size' => 50,
        'min_duration_minutes' => 30,
        'max_duration_minutes' => 240,
        'opening_time' => '08:00',
        'closing_time' => '23:00',
        'cancellation_cutoff_hours' => 24,
    ], $attributes));
}

// withoutEvents() skips the model's `saved` hook, which would otherwise try
// to sync this to Stripe over the network. Defaults to a one-time plan
// since that's the one that produces a Purchase (a recurring plan goes
// through Cashier's own subscriptions instead).
function makeSubscriptionType(array $attributes = []): SubscriptionType
{
    return SubscriptionType::withoutEvents(fn () => SubscriptionType::create(array_merge([
        'name' => 'Day pass',
        'price_cents' => 500,
        'billing_interval' => 'one_time',
        'visit_limit' => 1,
    ], $attributes)));
}

/**
 * Swaps the Stripe gateway for a fake so payment tests make no network calls.
 * `$sessions` maps checkout session id => its Stripe-side state; refunds are
 * recorded in `$gateway->refunds`, or throw when `$failRefunds` is set.
 *
 * @param  array<string, array<string, mixed>>  $sessions
 */
function fakeStripe(array $sessions = [], bool $failRefunds = false): object
{
    $gateway = new class($sessions, $failRefunds) extends StripeGateway
    {
        /** @var list<array{payment_intent: string, key: string}> */
        public array $refunds = [];

        /** @var list<string> */
        public array $cancelledSubscriptions = [];

        public bool $failSubscriptionCancels = false;

        public bool $failCheckoutSessions = false;

        /**
         * @param  array<string, array<string, mixed>>  $sessions
         */
        public function __construct(public array $sessions, public bool $failRefunds) {}

        public function checkoutSession(string $sessionId): Session
        {
            if ($this->failCheckoutSessions) {
                throw new RuntimeException('Stripe is down.');
            }

            return Session::constructFrom(
                ($this->sessions[$sessionId] ?? []) + ['id' => $sessionId, 'status' => 'open', 'payment_status' => 'unpaid', 'payment_intent' => null, 'customer' => null],
            );
        }

        /** @var array<string, int> payment intent id => cents already refunded */
        public array $refundedByIntent = [];

        public function refundedCents(string $paymentIntentId): int
        {
            return $this->refundedByIntent[$paymentIntentId] ?? 0;
        }

        /** @var list<array{amount: int, user: int}> */
        public array $checkouts = [];

        public function createReservationCheckout(User $user, int $amountCents, string $description, string $successUrl, string $cancelUrl, int $expiresAt): array
        {
            $this->checkouts[] = ['amount' => $amountCents, 'user' => $user->id];

            return ['id' => 'cs_fake_'.count($this->checkouts), 'url' => 'https://checkout.stripe.test/pay/'.count($this->checkouts)];
        }

        public function cancelSubscriptionNow(string $stripeSubscriptionId): void
        {
            if ($this->failSubscriptionCancels) {
                throw new RuntimeException('Stripe is down.');
            }

            $this->cancelledSubscriptions[] = $stripeSubscriptionId;
        }

        public function refund(string $paymentIntentId, string $idempotencyKey, ?int $amountCents = null): void
        {
            if ($this->failRefunds) {
                throw new RuntimeException('Stripe is down.');
            }

            $this->refunds[] = ['payment_intent' => $paymentIntentId, 'key' => $idempotencyKey];
        }
    };

    app()->instance(StripeGateway::class, $gateway);

    return $gateway;
}

/**
 * The (signed, expiring) code a customer's pass would show right now.
 */
function entryCode(User $user): string
{
    return EntryToken::issue($user)['token'];
}
