<?php

use App\Support\DatabaseBackup;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;

// These tests work on a throwaway SQLite *file* (the suite's own database is
// in-memory, which has nothing to back up), reached through a dedicated
// connection, with the storage path moved so snapshots never land in the
// real storage/app/backups.

beforeEach(function () {
    $this->workdir = sys_get_temp_dir().'/skatepark-backup-'.bin2hex(random_bytes(4));
    mkdir($this->workdir.'/storage/app', 0775, true);

    $this->originalStoragePath = $this->app->storagePath();
    $this->originalConnection = config('database.default');
    $this->app->useStoragePath($this->workdir.'/storage');

    $this->dbPath = $this->workdir.'/live.sqlite';
    $pdo = new PDO('sqlite:'.$this->dbPath);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('CREATE TABLE items (name TEXT)');
    $pdo->exec("INSERT INTO items VALUES ('original')");
    $pdo = null;

    config([
        'database.connections.backup_test' => [
            'driver' => 'sqlite',
            'database' => $this->dbPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);
    DB::purge('backup_test');

    $this->backups = new DatabaseBackup('backup_test');
});

afterEach(function () {
    config(['database.default' => $this->originalConnection]);
    DB::purge('backup_test');
    $this->app->useStoragePath($this->originalStoragePath);
    File::deleteDirectory($this->workdir);
});

function liveItems(): array
{
    return DB::connection('backup_test')->table('items')->pluck('name')->all();
}

test('a snapshot contains the data and passes the integrity check', function () {
    $path = $this->backups->create('manual');

    expect($path)->toStartWith($this->workdir.'/storage/app/backups/database-')
        ->and($path)->toEndWith('-manual.sqlite');

    $copy = new PDO('sqlite:'.$path);
    expect($copy->query('SELECT name FROM items')->fetchAll(PDO::FETCH_COLUMN))->toBe(['original']);
});

test('a snapshot includes rows that are only in the WAL so far', function () {
    // Held open so the WAL isn't checkpointed away when the writer closes.
    $reader = new PDO('sqlite:'.$this->dbPath);
    $reader->query('SELECT count(*) FROM items')->fetchAll();

    DB::connection('backup_test')->table('items')->insert(['name' => 'in-wal']);
    expect(filesize($this->dbPath.'-wal'))->toBeGreaterThan(0);

    $copy = new PDO('sqlite:'.$this->backups->create());

    expect($copy->query('SELECT name FROM items ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN))
        ->toBe(['original', 'in-wal']);
});

test('backups taken at the same instant do not collide', function () {
    $this->travelTo(now());

    expect($this->backups->create())->not->toBe($this->backups->create());
});

test('pruning keeps the newest snapshots of each label separately', function () {
    foreach (range(1, 3) as $i) {
        $this->backups->create();
        $this->backups->create('pre-deploy');
    }

    $deleted = $this->backups->prune(null, 2);

    expect($deleted)->toHaveCount(1);

    $remaining = collect($this->backups->all());
    expect($remaining->where('label', null))->toHaveCount(2)
        ->and($remaining->where('label', 'pre-deploy'))->toHaveCount(3);
});

test('restore swaps the database back and keeps a copy of what it replaced', function () {
    $snapshot = $this->backups->create();

    DB::connection('backup_test')->table('items')->insert(['name' => 'added later']);
    expect(liveItems())->toBe(['original', 'added later']);

    $safety = $this->backups->restore($snapshot);

    expect(liveItems())->toBe(['original'])
        ->and($safety)->toEndWith('-pre-restore.sqlite');

    $kept = new PDO('sqlite:'.$safety);
    expect($kept->query('SELECT name FROM items ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN))
        ->toBe(['original', 'added later']);
});

test('restore refuses a corrupt file and leaves the database alone', function () {
    $bad = $this->workdir.'/bad.sqlite';
    file_put_contents($bad, str_repeat('not a database', 100));

    expect(fn () => $this->backups->restore($bad))->toThrow(RuntimeException::class);
    expect(liveItems())->toBe(['original']);
});

test('restore resolves "latest" and bare file names', function () {
    $first = $this->backups->create();
    $second = $this->backups->create();

    expect($this->backups->resolve('latest'))->toBe($second)
        ->and($this->backups->resolve(basename($first)))->toBe(realpath($first));
});

test('an in-memory database cannot be backed up', function () {
    expect(fn () => (new DatabaseBackup)->create())
        ->toThrow(RuntimeException::class, 'in-memory');
});

test('db:backup and db:restore work through artisan', function () {
    config(['database.default' => 'backup_test']);

    $this->artisan('db:backup', ['--label' => 'pre-deploy'])->assertSuccessful();
    expect($this->backups->all())->toHaveCount(1);

    DB::connection('backup_test')->table('items')->insert(['name' => 'added later']);

    $this->artisan('db:restore', ['file' => 'latest', '--force' => true])->assertSuccessful();
    expect(liveItems())->toBe(['original']);
});

test('db:backup fails clearly when there is no database file', function () {
    unlink($this->dbPath);
    @unlink($this->dbPath.'-wal');
    @unlink($this->dbPath.'-shm');
    config(['database.default' => 'backup_test']);

    $this->artisan('db:backup')->assertFailed();
});

test('the backup runs on a schedule', function () {
    $commands = collect(app(Schedule::class)->events())->pluck('command')->implode("\n");

    expect($commands)->toContain('db:backup');
});

// A migration that writes through a second connection (so the write survives
// the migration's own rollback) and then fails — the situation the snapshot
// exists for.
function useFailingMigration(string $dir, string $dbPath): void
{
    mkdir($dir, 0775, true);
    file_put_contents($dir.'/2999_01_01_000000_fail_halfway.php', <<<PHP
        <?php

        use Illuminate\Database\Migrations\Migration;

        return new class extends Migration {
            public function up(): void
            {
                \$pdo = new PDO('sqlite:{$dbPath}');
                \$pdo->exec("INSERT INTO items VALUES ('half-applied')");

                throw new RuntimeException('boom');
            }
        };
        PHP);

    app('migrator')->path($dir);
}

test('db:migrate-safe snapshots, migrates, and keeps the result when it works', function () {
    config(['database.default' => 'backup_test']);

    $this->artisan('db:migrate-safe')->assertSuccessful();

    expect(Schema::connection('backup_test')->hasTable('users'))->toBeTrue()
        ->and(liveItems())->toBe(['original'])
        ->and(collect($this->backups->all())->where('label', 'pre-migrate'))->toHaveCount(1);
});

test('db:migrate-safe restores the snapshot when a migration fails', function () {
    config(['database.default' => 'backup_test']);
    useFailingMigration($this->workdir.'/failing', $this->dbPath);

    $this->artisan('db:migrate-safe')->assertFailed();

    DB::purge('backup_test');

    expect(liveItems())->toBe(['original'])
        ->and(Schema::connection('backup_test')->hasTable('users'))->toBeFalse();
});

test('db:migrate-safe does nothing when everything has already run', function () {
    config(['database.default' => 'backup_test']);
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $this->artisan('db:migrate-safe')
        ->expectsOutputToContain('Nothing to migrate')
        ->assertSuccessful();

    expect($this->backups->all())->toBe([]);
});

test('db:migrate-safe creates a missing database file on first run', function () {
    unlink($this->dbPath);
    @unlink($this->dbPath.'-wal');
    @unlink($this->dbPath.'-shm');
    config(['database.default' => 'backup_test']);

    $this->artisan('db:migrate-safe')->assertSuccessful();

    expect(Schema::connection('backup_test')->hasTable('users'))->toBeTrue();
});

// The pre-launch switch: every deploy can wipe and reseed the database, but
// only on purpose (FRESH_DB_ON_DEPLOY=true), and never half way.

test('db:fresh-deploy leaves the database alone unless the switch is on', function () {
    config(['database.default' => 'backup_test', 'app.fresh_db_on_deploy' => false]);

    $this->artisan('db:fresh-deploy')->assertFailed();

    expect(liveItems())->toBe(['original'])
        ->and($this->backups->all())->toBe([]);
});

test('db:fresh-deploy refuses to wipe production without a seed password', function () {
    config(['database.default' => 'backup_test', 'app.fresh_db_on_deploy' => true, 'app.seed_password' => null]);
    $this->app['env'] = 'production';

    $this->artisan('db:fresh-deploy')->assertFailed();

    expect(liveItems())->toBe(['original'])
        ->and($this->backups->all())->toBe([]);
});

test('db:fresh-deploy restores the snapshot when the wipe fails', function () {
    config(['database.default' => 'backup_test', 'app.fresh_db_on_deploy' => true, 'app.seed_password' => 'a-long-seed-secret']);
    useFailingMigration($this->workdir.'/failing', $this->dbPath);

    $this->artisan('db:fresh-deploy')->assertFailed();

    DB::purge('backup_test');

    expect(liveItems())->toBe(['original'])
        ->and(Schema::connection('backup_test')->hasTable('users'))->toBeFalse();
});

test('the seeder refuses to create staff accounts in production without a seed password', function () {
    config(['app.seed_password' => null]);
    $this->app['env'] = 'production';

    expect(fn () => (new DatabaseSeeder)->run())->toThrow(RuntimeException::class, 'SEED_PASSWORD');
});

// The same command as a real process, in production mode, against a throwaway
// database file that has already been migrated and has data in it — the
// closest a test gets to what docker/deploy.sh does on the server. This is
// also the only place the destructive-command guard (on in production) is
// exercised for real.

function runFreshDeployProcess(string $workdir, array $env): ProcessResult
{
    $database = $workdir.'/deployed.sqlite';
    $storage = $workdir.'/proc-storage';

    if (! is_dir($storage)) {
        foreach (['app/backups', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
            mkdir("{$storage}/{$dir}", 0775, true);
        }
    }

    $base = [
        'APP_ENV' => 'production',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $database,
        'DB_URL' => '',
        'LARAVEL_STORAGE_PATH' => $storage,
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'FRESH_DB_ON_DEPLOY' => '',
        'SEED_PASSWORD' => '',
    ];

    if (! file_exists($database)) {
        touch($database);
        $deployed = Process::path(base_path())->env($base)->run('php artisan migrate --force');
        expect($deployed->successful())->toBeTrue($deployed->output().$deployed->errorOutput());

        $pdo = new PDO('sqlite:'.$database);
        $pdo->exec('CREATE TABLE items (name TEXT)');
        $pdo->exec("INSERT INTO items VALUES ('real data')");
        $pdo = null;
    }

    return Process::path(base_path())->env(array_merge($base, $env))->run('php artisan db:fresh-deploy');
}

function deployedTables(string $workdir): array
{
    $pdo = new PDO('sqlite:'.$workdir.'/deployed.sqlite');

    return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
}

test('db:fresh-deploy as a process leaves a deployed database alone when the switch is off', function () {
    $result = runFreshDeployProcess($this->workdir, ['FRESH_DB_ON_DEPLOY' => 'false', 'SEED_PASSWORD' => 'a-long-seed-secret']);

    expect($result->failed())->toBeTrue()
        ->and(deployedTables($this->workdir))->toContain('items');
});

test('db:fresh-deploy as a process refuses to wipe production without a seed password', function () {
    $result = runFreshDeployProcess($this->workdir, ['FRESH_DB_ON_DEPLOY' => 'true']);

    expect($result->failed())->toBeTrue()
        ->and($result->output())->toContain('SEED_PASSWORD')
        ->and(deployedTables($this->workdir))->toContain('items');
});

test('db:fresh-deploy as a process wipes and reseeds production, past the destructive-command guard', function () {
    $result = runFreshDeployProcess($this->workdir, ['FRESH_DB_ON_DEPLOY' => 'true', 'SEED_PASSWORD' => 'a-long-seed-secret']);

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput())
        ->and(deployedTables($this->workdir))->not->toContain('items')->toContain('users');

    $pdo = new PDO('sqlite:'.$this->workdir.'/deployed.sqlite');
    $hash = $pdo->query("SELECT password FROM users WHERE email = 'admin@xn--rull-eva.lv'")->fetchColumn();

    expect($pdo->query('SELECT COUNT(*) FROM users')->fetchColumn())->toBe(2)
        ->and(Hash::check('a-long-seed-secret', $hash))->toBeTrue()
        ->and(Hash::check('password', $hash))->toBeFalse()
        ->and(glob($this->workdir.'/proc-storage/app/backups/*-pre-fresh.sqlite'))->toHaveCount(1);
});
