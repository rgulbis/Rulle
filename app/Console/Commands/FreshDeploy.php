<?php

namespace App\Console\Commands;

use App\Support\DatabaseBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The pre-launch "every deploy starts from an empty database" switch. Run by
 * docker/deploy.sh once per deploy, and only when the FRESH_DB_ON_DEPLOY
 * GitHub variable is "true" - it refuses to do anything otherwise, so running
 * it by hand against real data by mistake does nothing.
 */
class FreshDeploy extends Command
{
    protected $signature = 'db:fresh-deploy';

    protected $description = 'Wipe the database and reseed it (only when FRESH_DB_ON_DEPLOY is true); restores a snapshot if that fails';

    public function handle(DatabaseBackup $backups): int
    {
        if (config('app.fresh_db_on_deploy') !== true) {
            $this->error('FRESH_DB_ON_DEPLOY is not "true"; leaving the database alone.');

            return self::FAILURE;
        }

        // Checked before anything is wiped: failing half way through a wipe
        // is the one thing this must not do.
        if (app()->isProduction() && ! config('app.seed_password')) {
            $this->error('Refusing to wipe: production needs SEED_PASSWORD so the seeded staff accounts are not left with a well-known password.');

            return self::FAILURE;
        }

        $snapshot = $this->snapshot($backups);

        $this->warn('Wiping the database and reseeding it (FRESH_DB_ON_DEPLOY is on).');

        // `migrate:fresh` is blocked in production on purpose (see
        // AppServiceProvider). The switch above is the deliberate exception.
        DB::prohibitDestructiveCommands(false);

        try {
            $exitCode = $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);
            $failure = $exitCode === 0 ? null : "migrate:fresh exited with status {$exitCode}";
        } catch (Throwable $e) {
            $failure = $e->getMessage();
        }

        if ($failure === null) {
            $this->info('Database is fresh.');

            return self::SUCCESS;
        }

        $this->error("Wiping failed: {$failure}");

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
     * @return string|null Path of the snapshot, or null when there is no data worth keeping.
     */
    private function snapshot(DatabaseBackup $backups): ?string
    {
        $path = $backups->databasePath();

        if (! is_file($path) || filesize($path) === 0) {
            return null;
        }

        $snapshot = $backups->create('pre-fresh');
        $backups->prune('pre-fresh', 10);
        $this->line("Snapshot before wiping: {$snapshot}");

        return $snapshot;
    }
}
