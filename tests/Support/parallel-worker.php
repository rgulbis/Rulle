<?php

/*
| One process of a parallel-request test (see tests/Feature/ParallelRequestsTest.php).
| Boots the real application against the SQLite *file* the test points it at,
| waits for a shared start time so the workers genuinely overlap, performs one
| action and prints its outcome. Not a test file itself.
|
| Usage: php parallel-worker.php <scenario> <json payload> <start-at (unix float)>
*/

use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\ReservationSetting;
use App\Models\SubscriptionType;
use App\Models\User;
use App\Support\EntryToken;
use App\Support\Payments\CheckoutFulfillment;
use App\Support\Payments\ReservationBooking;
use App\Support\Payments\StripeGateway;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

[, $scenario, $json, $startAt] = $argv + [null, null, '{}', 0];
$p = json_decode($json, true);

$wait = function () use ($startAt) {
    while (microtime(true) < (float) $startAt) {
        usleep(200);
    }
};

$http = function (int $userId, string $method, string $uri, array $data) use ($app) {
    auth()->guard('web')->setUser(User::findOrFail($userId));
    $request = Request::create($uri, $method, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($data));

    return $app->make(HttpKernel::class)->handle($request);
};

try {
    switch ($scenario) {
        case 'seed':
            ReservationSetting::current();
            foreach ($p['users'] as $i => $role) {
                User::factory()->create(['role' => $role, 'name' => "Worker user {$i}"]);
            }
            echo 'SEEDED';
            break;

        case 'seed-reservations':
            // Two overlapping *pending* reservations, each with a checkout session.
            foreach ([0, 1] as $i) {
                Reservation::create([
                    'user_id' => $p['owners'][$i],
                    'starts_at' => Carbon::parse('2031-01-01 10:00')->addMinutes($i * 30),
                    'ends_at' => Carbon::parse('2031-01-01 11:00')->addMinutes($i * 30),
                    'group_size' => 3,
                    'price_cents' => 1500,
                    'status' => 'pending',
                    'stripe_checkout_session_id' => "cs_race_{$i}",
                ]);
            }
            echo 'SEEDED';
            break;

        case 'seed-pass':
            $type = SubscriptionType::withoutEvents(fn () => SubscriptionType::create([
                'name' => 'One visit', 'price_cents' => 500, 'billing_interval' => 'one_time',
                'visit_limit' => 1, 'unlimited_entries' => false, 'active' => true,
            ]));
            Purchase::create([
                'user_id' => $p['user'], 'subscription_type_id' => $type->id,
                'stripe_checkout_session_id' => 'cs_pass_race', 'status' => 'active',
                'visits_remaining' => 1, 'price_cents' => 500, 'payment_status' => 'paid',
            ]);
            echo 'SEEDED';
            break;

        case 'seed-group':
            // Owner's reservation for 3 people (2 seats) with one seat already invited.
            $r = Reservation::create([
                'user_id' => $p['owner'], 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
                'group_size' => 3, 'price_cents' => 1500, 'status' => 'active', 'payment_status' => 'paid',
            ]);
            $r->allParticipants()->attach($p['existing'], ['status' => 'invited']);
            echo $r->id;
            break;

        case 'book':
            $wait();
            $r = app(ReservationBooking::class)->createPending(
                ['user_id' => $p['user'], 'group_size' => 3, 'price_cents' => 1500],
                Carbon::parse('2031-02-01 10:00'),
                Carbon::parse('2031-02-01 11:00'),
            );
            echo $r ? 'CREATED' : 'TAKEN';
            break;

        case 'fulfill':
            app()->instance(StripeGateway::class, new class extends StripeGateway
            {
                public function refund(string $paymentIntentId, string $idempotencyKey, ?int $amountCents = null): void {}
            });
            $wait();
            app(CheckoutFulfillment::class)->fulfill($p['session'], 'pi_'.$p['session']);
            echo 'FULFILLED';
            break;

        case 'scan':
            $wait();
            $client = User::findOrFail($p['client']);
            echo $http($p['staff'], 'POST', '/staff/scan', ['code' => EntryToken::issue($client)['token'], 'mode' => 'entry'])->getStatusCode();
            break;

        case 'invite':
            $wait();
            echo $http($p['owner'], 'POST', "/reservations/{$p['reservation']}/participants", ['user_id' => $p['invitee']])->getStatusCode();
            break;

        default:
            echo "UNKNOWN {$scenario}";
    }
} catch (Throwable $e) {
    echo 'ERROR '.get_class($e).': '.$e->getMessage();
}
