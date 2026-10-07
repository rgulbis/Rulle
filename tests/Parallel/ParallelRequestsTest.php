<?php

/*
 * Races, for real. A single test process can't show a race — its requests run
 * one after another — so each test here starts several PHP processes against
 * one SQLite *file*, holds them at a barrier, releases them together and then
 * checks that exactly one contender won. These are what fail when
 * `transaction_mode` goes back to DEFERRED or a lock is taken out.
 */

use App\Models\CheckInEvent;
use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->databaseFile = sys_get_temp_dir().'/skatepark-parallel-'.bin2hex(random_bytes(4)).'.sqlite';
    touch($this->databaseFile);

    config(['database.connections.sqlite.database' => $this->databaseFile]);
    DB::purge('sqlite');
    Artisan::call('migrate', ['--force' => true]);

    trackedFiles(reset: true);
});

afterEach(function () {
    DB::disconnect('sqlite');

    foreach ([$this->databaseFile, ...trackedFiles()] as $file) {
        foreach ([$file, "{$file}-wal", "{$file}-shm"] as $path) {
            @unlink($path);
        }
    }
});

/**
 * Scratch files (barriers, logs) to delete once the test is over.
 *
 * @return list<string>
 */
function trackedFiles(?string $add = null, bool $reset = false): array
{
    static $files = [];

    if ($reset) {
        $files = [];
    }

    if ($add !== null) {
        $files[] = $add;
    }

    return $files;
}

/**
 * Runs the given contenders at the same instant.
 *
 * @param  list<array<string, mixed>>  $contenders  one spec per process
 * @param  array<string, mixed>  $shared  spec fields every process gets
 * @return list<array{status: int, body?: string}>
 */
function race(array $contenders, array $shared = []): array
{
    $test = test();
    $id = bin2hex(random_bytes(4));
    $barrier = sys_get_temp_dir()."/skatepark-barrier-{$id}";
    trackedFiles($barrier);

    $processes = [];
    $readyFiles = [];

    foreach ($contenders as $i => $contender) {
        $ready = sys_get_temp_dir()."/skatepark-ready-{$id}-{$i}";
        trackedFiles($ready);
        $readyFiles[] = $ready;

        $spec = array_merge([
            'app_key' => config('app.key'),
            'database' => $test->databaseFile,
            'barrier' => $barrier,
            'ready' => $ready,
            'user_id' => null,
        ], $shared, $contender);

        $process = new Process([PHP_BINARY, __DIR__.'/../Support/parallel-worker.php', json_encode($spec)]);
        $process->setTimeout(60);
        $process->start();
        $processes[] = $process;
    }

    // Wait until every process has booted, so boot time isn't what decides who wins.
    $deadline = microtime(true) + 30;
    while (array_filter($readyFiles, fn (string $file) => ! file_exists($file)) !== []) {
        foreach ($processes as $process) {
            expect($process->isRunning() || $process->isSuccessful())->toBeTrue("worker died while booting: {$process->getErrorOutput()}{$process->getOutput()}");
        }
        expect(microtime(true))->toBeLessThan($deadline, 'workers did not boot in time');
        usleep(5000);
    }

    touch($barrier);

    $results = [];
    foreach ($processes as $process) {
        $process->wait();
        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
        $results[] = json_decode((string) end($lines), true) ?? ['status' => 599, 'body' => $process->getErrorOutput().$process->getOutput()];
    }

    return $results;
}

function statuses(array $results): array
{
    return array_map(fn (array $r) => $r['status'], $results);
}

/**
 * Nothing may blow up: a race lost must be a clean "no", never a 500.
 */
function expectNoServerErrors(array $results): void
{
    foreach ($results as $result) {
        expect($result['status'])->toBeLessThan(500, $result['body'] ?? '');
    }
}

test('simultaneous bookings of the same slot create exactly one reservation', function () {
    makeReservationSettings();
    $users = User::factory()->count(4)->create();
    $startsAt = now()->addDay()->setTime(12, 0);

    $results = race($users->map(fn (User $user) => [
        'user_id' => $user->id,
        'method' => 'POST',
        'uri' => '/reservations',
        'params' => ['starts_at' => $startsAt->toDateTimeString(), 'duration_minutes' => 60, 'group_size' => 3],
    ])->all());

    expectNoServerErrors($results);
    expect(Reservation::count())->toBe(1);
});

test('simultaneous payments for overlapping slots activate exactly one and refund the other', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $slot = [now()->addDay()->setTime(12, 0), now()->addDay()->setTime(13, 0)];

    makeReservation($a, ...$slot, attributes: ['status' => 'pending', 'stripe_checkout_session_id' => 'cs_a']);
    makeReservation($b, $slot[0]->addMinutes(30), $slot[1]->addMinutes(30), ['status' => 'pending', 'stripe_checkout_session_id' => 'cs_b']);

    $results = race([
        ['action' => 'fulfil', 'session' => 'cs_a'],
        ['action' => 'fulfil', 'session' => 'cs_b'],
        ['action' => 'fulfil', 'session' => 'cs_a'],
        ['action' => 'fulfil', 'session' => 'cs_b'],
    ]);

    expectNoServerErrors($results);
    expect(Reservation::where('status', 'active')->count())->toBe(1);
    expect(Reservation::where('status', 'cancelled')->where('payment_status', 'refunded')->count())->toBe(1);
});

test('simultaneous scans of one code let it in once and spend one visit', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $client = User::factory()->create();
    Purchase::create([
        'user_id' => $client->id,
        'subscription_type_id' => makeSubscriptionType(['visit_limit' => 3])->id,
        'stripe_checkout_session_id' => 'cs_pass',
        'status' => 'active',
        'payment_status' => 'paid',
        'visits_remaining' => 3,
    ]);

    // Four different tokens for the same rider: four scanners, four
    // refreshes of the same dashboard.
    $results = race(array_map(fn () => [
        'user_id' => $staff->id,
        'method' => 'POST',
        'uri' => '/staff/scan',
        'params' => ['code' => qrTokenFor($client), 'mode' => 'entry'],
    ], range(1, 4)));

    expectNoServerErrors($results);
    $counts = array_count_values(statuses($results));
    ksort($counts);
    expect($counts)->toBe([200 => 1, 409 => 3]);
    expect(CheckInEvent::where('user_id', $client->id)->count())->toBe(1);
    expect(Purchase::where('user_id', $client->id)->value('visits_remaining'))->toBe(2);
});

test('simultaneous scans on the last visit let in one person and use the pass up', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $client = User::factory()->create();
    Purchase::create([
        'user_id' => $client->id,
        'subscription_type_id' => makeSubscriptionType(['visit_limit' => 1])->id,
        'stripe_checkout_session_id' => 'cs_pass',
        'status' => 'active',
        'payment_status' => 'paid',
        'visits_remaining' => 1,
    ]);

    $results = race(array_map(fn () => [
        'user_id' => $staff->id,
        'method' => 'POST',
        'uri' => '/staff/scan',
        'params' => ['code' => qrTokenFor($client), 'mode' => 'entry'],
    ], range(1, 3)));

    expectNoServerErrors($results);
    expect(CheckInEvent::where('user_id', $client->id)->count())->toBe(1);

    $purchase = Purchase::where('user_id', $client->id)->first();
    expect($purchase->visits_remaining)->toBe(0)->and($purchase->status)->toBe('used_up');
});

test('simultaneously accepting invitations never goes past the paid group size', function () {
    $owner = User::factory()->create();
    $friends = User::factory()->count(5)->create();
    // group_size 3 = the owner plus two guests.
    $reservation = makeReservation($owner, now()->addDay()->setTime(12, 0), now()->addDay()->setTime(13, 0), ['group_size' => 3]);
    // Invitations take no seat, so all five can be invited.
    $reservation->invitations()->attach($friends->pluck('id'));

    $results = race($friends->map(fn (User $friend) => [
        'user_id' => $friend->id,
        'method' => 'POST',
        'uri' => "/reservations/{$reservation->id}/invitation/accept",
        'params' => [],
    ])->all());

    expectNoServerErrors($results);
    expect($reservation->participants()->count())->toBe(2);
});

test('a burst of messages from one user in slow mode gets one through', function () {
    $user = User::factory()->create();
    Cache::store('database')->put('chat.slow_mode_until', now()->getTimestamp() + 120, 180);

    $results = race(array_fill(0, 4, [
        'user_id' => $user->id,
        'method' => 'POST',
        'uri' => '/chat',
        'params' => ['body' => 'hello there'],
    ]));

    expectNoServerErrors($results);
    expect(DB::table('chat_messages')->where('user_id', $user->id)->count())->toBe(1);
});

test('double-clicking subscribe opens one Stripe checkout, not several', function () {
    $user = User::factory()->create();
    $plan = makeSubscriptionType(['name' => 'Monthly', 'billing_interval' => 'month', 'active' => true]);
    $plan->forceFill(['stripe_product_id' => 'prod_test', 'stripe_price_id' => 'price_test'])->save();

    $log = sys_get_temp_dir().'/skatepark-stripe-'.bin2hex(random_bytes(4));
    trackedFiles($log);

    $results = race(array_fill(0, 4, [
        'user_id' => $user->id,
        'method' => 'POST',
        'uri' => "/subscriptions/{$plan->id}/checkout",
    ]), ['stripe_log' => $log]);

    expectNoServerErrors($results);
    expect(file($log, FILE_IGNORE_NEW_LINES))->toHaveCount(1);
});
