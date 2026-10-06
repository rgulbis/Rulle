<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Snapshots and restores the SQLite database file.
 *
 * Snapshots use `VACUUM INTO`, which produces a consistent copy while the
 * app is still serving requests (a plain file copy of a WAL-mode database
 * can miss committed data that is still sitting in the -wal file).
 *
 * Restoring replaces the file on disk, so any process that already has the
 * database open keeps looking at the old one — stop the app first.
 */
class DatabaseBackup
{
    private const NAME_PATTERN = '/^database-(\d{8})-(\d{6})-(\d{6})(?:-(?<label>[a-z0-9-]+))?\.sqlite$/';

    public function __construct(private readonly ?string $connection = null) {}

    public function databasePath(): string
    {
        $name = $this->connection ?? config('database.default');
        $config = config("database.connections.{$name}");

        if (($config['driver'] ?? null) !== 'sqlite') {
            throw new RuntimeException("Connection [{$name}] is not a SQLite connection; backups only support SQLite.");
        }

        $path = (string) ($config['database'] ?? '');

        if ($path === '' || $path === ':memory:' || str_contains($path, 'mode=memory')) {
            throw new RuntimeException('The database is in-memory; there is no file to back up.');
        }

        // Same resolution order as Laravel's SQLite connector, except that an
        // absolute path to a file that doesn't exist yet stays as it is.
        if (str_starts_with($path, '/')) {
            return realpath($path) ?: $path;
        }

        return realpath($path) ?: base_path($path);
    }

    public function directory(): string
    {
        return storage_path('app/backups');
    }

    /**
     * @return string Absolute path of the new snapshot.
     */
    public function create(?string $label = null): string
    {
        $label = $this->normalizeLabel($label);
        $source = $this->databasePath();

        if (! is_file($source)) {
            throw new RuntimeException("Database file [{$source}] does not exist; nothing to back up.");
        }

        if (! is_dir($this->directory()) && ! mkdir($this->directory(), 0775, true) && ! is_dir($this->directory())) {
            throw new RuntimeException("Could not create backup directory [{$this->directory()}].");
        }

        // Microsecond precision keeps names unique and sortable; the loop
        // only matters when the clock is frozen (tests).
        $time = now('UTC');

        do {
            $target = sprintf(
                '%s/database-%s%s.sqlite',
                $this->directory(),
                $time->format('Ymd-His-u'),
                $label === null ? '' : "-{$label}",
            );
            $time = $time->addMicrosecond();
        } while (file_exists($target));

        $pdo = DB::connection($this->connection)->getPdo();
        $pdo->exec('VACUUM INTO '.$pdo->quote($target));

        try {
            $this->verify($target);
        } catch (RuntimeException $e) {
            @unlink($target);

            throw $e;
        }

        return $target;
    }

    /**
     * Replace the live database with a snapshot.
     *
     * @return string|null Path of the safety copy taken of the previous
     *                     database, or null when there was nothing to copy
     *                     (or $safetyCopy was false).
     */
    public function restore(string $file, bool $safetyCopy = true): ?string
    {
        $file = $this->resolve($file);
        $this->verify($file);

        $destination = $this->databasePath();
        $safety = null;

        if ($safetyCopy && is_file($destination) && filesize($destination) > 0) {
            $safety = $this->create('pre-restore');
        }

        $staging = $destination.'.restoring';

        if (! copy($file, $staging)) {
            throw new RuntimeException("Could not copy [{$file}] next to the database.");
        }

        DB::purge($this->connection);

        // A leftover WAL belongs to the file being replaced; applying it to
        // the restored one would corrupt it.
        @unlink($destination.'-wal');
        @unlink($destination.'-shm');

        if (! rename($staging, $destination)) {
            @unlink($staging);

            throw new RuntimeException("Could not move the restored database into place at [{$destination}].");
        }

        $this->verify($destination);

        return $safety;
    }

    /**
     * Delete all but the newest $keep snapshots that share $label.
     *
     * @return list<string> Names of the deleted files.
     */
    public function prune(?string $label, int $keep): array
    {
        $label = $this->normalizeLabel($label);
        $deleted = [];

        $matching = array_values(array_filter(
            $this->all(),
            fn (array $backup) => $backup['label'] === $label,
        ));

        foreach (array_slice($matching, max($keep, 1)) as $backup) {
            if (@unlink($backup['path'])) {
                $deleted[] = $backup['name'];
            }
        }

        return $deleted;
    }

    /**
     * @return list<array{name: string, path: string, label: string|null, size: int, taken_at: string}> Newest first.
     */
    public function all(): array
    {
        $backups = [];

        foreach (glob($this->directory().'/database-*.sqlite') ?: [] as $path) {
            $name = basename($path);

            if (! preg_match(self::NAME_PATTERN, $name, $m)) {
                continue;
            }

            $backups[] = [
                'name' => $name,
                'path' => $path,
                'label' => ($m['label'] ?? '') === '' ? null : $m['label'],
                'size' => (int) filesize($path),
                'taken_at' => $m[1].$m[2],
            ];
        }

        usort($backups, fn (array $a, array $b) => strcmp($b['name'], $a['name']));

        return $backups;
    }

    /**
     * Accepts "latest", a bare file name from the backup directory, or a path.
     */
    public function resolve(string $file): string
    {
        if ($file === 'latest') {
            $latest = $this->all()[0] ?? null;

            if ($latest === null) {
                throw new RuntimeException('There are no backups to restore.');
            }

            return $latest['path'];
        }

        foreach ([$file, $this->directory().'/'.basename($file)] as $candidate) {
            if (is_file($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        throw new RuntimeException("Backup [{$file}] was not found.");
    }

    /**
     * Throws unless $file is an intact SQLite database.
     */
    public function verify(string $file): void
    {
        try {
            $pdo = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $statement = $pdo->query('PRAGMA integrity_check');
            $result = $statement === false ? ['integrity_check could not run'] : $statement->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            throw new RuntimeException("[{$file}] is not a usable SQLite database: {$e->getMessage()}", previous: $e);
        } finally {
            $pdo = null;
        }

        if ($result !== ['ok']) {
            throw new RuntimeException("[{$file}] failed the integrity check: ".implode('; ', $result));
        }
    }

    private function normalizeLabel(?string $label): ?string
    {
        if ($label === null || $label === '') {
            return null;
        }

        if (! preg_match('/^[a-z0-9-]+$/', $label)) {
            throw new RuntimeException('Backup labels may only contain lowercase letters, digits and dashes.');
        }

        return $label;
    }
}
