<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
| Real concurrency, not simulated: several PHP processes hit one SQLite *file*
| at the same instant (the in-memory test database can't be shared between
| processes). Each test asserts the invariant that matters — one winner, no
| double spend, no overbooking — whatever order the processes land in.
*/

beforeEach(function () {
    $this->dbFile = sys_get_temp_dir().'/skatepark-parallel-'.uniqid().'.sqlite';
    touch($this->dbFile);

    $this->env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $this->dbFile,
        'CACHE_STORE' => 'database',
        'SESSION_DRIVER' => 'array',
        'BROADCAST_CONNECTION' => 'null',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
        'BCRYPT_ROUNDS' => '4',
        'STRIPE_KEY' => 'pk_test_x',
        'STRIPE_SECRET' => 'sk_test_x',
    ];

    $migrated = Process::path(base_path())->env($this->env)->timeout(120)->run([PHP_BINARY, 'artisan', 'migrate', '--force']);
    expect($migrated->successful())->toBeTrue($migrated->output().$migrated->errorOutput());

    // Runs one worker alone (setup).
    $this->worker = fn (string $scenario, array $payload = []) => trim(Process::path(base_path())->env($this->env)->timeout(60)->run([
        PHP_BINARY, base_path('tests/Support/parallel-worker.php'), $scenario, json_encode($payload), '0',
    ])->output());

    // Runs one worker per payload, all released at the same moment.
    $this->parallel = function (string $scenario, array $payloads) {
        $startAt = microtime(true) + 2.5;
        $processes = [];

        foreach ($payloads as $payload) {
            $processes[] = Process::path(base_path())->env($this->env)->timeout(60)->start([
                PHP_BINARY, base_path('tests/Support/parallel-worker.php'), $scenario, json_encode($payload), (string) $startAt,
            ]);
        }

        return array_map(fn ($process) => trim($process->wait()->output()), $processes);
    };

    $this->db = fn () => new PDO('sqlite:'.$this->dbFile);
});

afterEach(function () {
    File::delete([$this->dbFile, $this->dbFile.'-wal', $this->dbFile.'-shm']);
});

test('six simultaneous bookings of the same slot create exactly one reservation', function () {
    ($this->worker)('seed', ['users' => array_fill(0, 6, 'user')]);

    $results = ($this->parallel)('book', array_map(fn ($id) => ['user' => $id], range(1, 6)));

    expect(array_count_values($results))->toEqual(['CREATED' => 1, 'TAKEN' => 5]);
    expect((int) ($this->db)()->query('select count(*) from reservations')->fetchColumn())->toBe(1);
});

test('two overlapping reservations paid at the same instant never both become active', function () {
    ($this->worker)('seed', ['users' => ['user', 'user']]);
    ($this->worker)('seed-reservations', ['owners' => [1, 2]]);

    $results = ($this->parallel)('fulfill', [['session' => 'cs_race_0'], ['session' => 'cs_race_1']]);

    // No worker may fail — in particular none may trip the database's
    // overlap trigger, which would mean the application check lost the race.
    expect($results)->toBe(['FULFILLED', 'FULFILLED']);

    $rows = ($this->db)()->query('select status, payment_status from reservations order by id')->fetchAll(PDO::FETCH_NUM);
    $active = array_filter($rows, fn ($row) => $row[0] === 'active');

    expect($active)->toHaveCount(1);
    expect(collect($rows)->pluck(1)->sort()->values()->all())->toBe(['paid', 'refunded']);
});

test('four simultaneous scans of one single-visit pass let exactly one person in and spend one visit', function () {
    ($this->worker)('seed', ['users' => ['employee', 'user']]);
    ($this->worker)('seed-pass', ['user' => 2]);

    $results = ($this->parallel)('scan', array_fill(0, 4, ['staff' => 1, 'client' => 2]));

    expect(array_count_values($results))->toEqual([200 => 1, 409 => 3]);

    $pdo = ($this->db)();
    expect((int) $pdo->query('select count(*) from check_in_events')->fetchColumn())->toBe(1);
    expect($pdo->query('select visits_remaining, status from purchases')->fetch(PDO::FETCH_NUM))->toBe([0, 'used_up']);
});

test('five simultaneous invitations for the last seat fill it exactly once', function () {
    ($this->worker)('seed', ['users' => ['user', 'user', 'user', 'user', 'user', 'user', 'user']]);
    // Owner = 1, one seat already invited (user 2), one seat left; candidates 3..7.
    $reservationId = (int) ($this->worker)('seed-group', ['owner' => 1, 'existing' => 2]);

    $results = ($this->parallel)('invite', array_map(
        fn ($invitee) => ['owner' => 1, 'reservation' => $reservationId, 'invitee' => $invitee],
        range(3, 7),
    ));

    expect(collect($results)->filter(fn ($r) => str_starts_with($r, 'ERROR'))->all())->toBe([]);
    expect((int) ($this->db)()->query('select count(*) from reservation_user')->fetchColumn())->toBe(2);
});
