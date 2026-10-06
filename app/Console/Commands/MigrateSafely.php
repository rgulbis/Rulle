<?php

namespace App\Console\Commands;

use App\Support\DatabaseBackup;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Throwable;

class MigrateSafely extends Command
{
    protected $signature = 'db:migrate-safe';

    protected $description = 'Run pending migrations, snapshotting first and restoring the snapshot if they fail';

    public function handle(DatabaseBackup $backups, Migrator $migrator): int
    {
        // `migrate` creates a missing SQLite file itself; the pending check
        // below would trip over it first.
        $database = $backups->databasePath();
        if (! is_file($database)) {
            touch($database);
        }

        if ($this->pendingMigrations($migrator) === 0) {
            $this->info('Nothing to migrate.');

            return self::SUCCESS;
        }

        $snapshot = $this->snapshot($backups);

        try {
            $exitCode = $this->call('migrate', ['--force' => true]);
            $failure = $exitCode === 0 ? null : "migrate exited with status {$exitCode}";
        } catch (Throwable $e) {
            $failure = $e->getMessage();
        }

        if ($failure === null) {
            return self::SUCCESS;
        }

        $this->error("Migration failed: {$failure}");

        if ($snapshot === null) {
            $this->error('There was no snapshot to restore (the database was empty or missing).');

            return self::FAILURE;
        }

        try {
            $backups->restore($snapshot, safetyCopy: false);
            $this->warn("Database restored from {$snapshot}");
        } catch (Throwable $e) {
            $this->error("Restoring the snapshot failed too: {$e->getMessage()}");
            $this->error("Restore it by hand: php artisan db:restore {$snapshot}");
        }

        return self::FAILURE;
    }

    /**
     * @return string|null Path of the snapshot, or null when there is no data worth snapshotting.
     */
    private function snapshot(DatabaseBackup $backups): ?string
    {
        $path = $backups->databasePath();

        if (! is_file($path) || filesize($path) === 0) {
            return null;
        }

        $snapshot = $backups->create('pre-migrate');
        $backups->prune('pre-migrate', 10);
        $this->line("Snapshot before migrating: {$snapshot}");

        return $snapshot;
    }

    private function pendingMigrations(Migrator $migrator): int
    {
        $files = $migrator->getMigrationFiles(
            array_merge($migrator->paths(), [database_path('migrations')]),
        );

        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

        return count(array_diff(array_keys($files), $ran));
    }
}
