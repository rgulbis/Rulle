<?php

namespace App\Console\Commands;

use App\Support\DatabaseBackup;
use Illuminate\Console\Command;
use RuntimeException;

class RestoreDatabase extends Command
{
    protected $signature = 'db:restore
        {file? : Backup file name or path, or "latest". Omit to list the available backups}
        {--force : Do not ask for confirmation}
        {--no-safety-copy : Do not snapshot the current database before replacing it}';

    protected $description = 'Replace the SQLite database with a snapshot (stop the app first)';

    public function handle(DatabaseBackup $backups): int
    {
        $file = $this->argument('file');

        if ($file === null) {
            return $this->listBackups($backups);
        }

        try {
            $path = $backups->resolve($file);
            $backups->verify($path);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->warn('This replaces the live database. Processes that already have it open keep the old file -');
        $this->warn('run it with the app stopped (docker compose stop app reverb scheduler).');

        if (! $this->option('force') && ! $this->confirm("Restore from [{$path}]?")) {
            $this->line('Aborted.');

            return self::FAILURE;
        }

        try {
            $safety = $backups->restore($path, ! $this->option('no-safety-copy'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            if (! $this->option('no-safety-copy')) {
                $this->line('If the current database is unreadable, retry with --no-safety-copy.');
            }

            return self::FAILURE;
        }

        if ($safety !== null) {
            $this->line("Previous database saved as: {$safety}");
        }

        $this->info('Database restored.');

        return self::SUCCESS;
    }

    private function listBackups(DatabaseBackup $backups): int
    {
        $all = $backups->all();

        if ($all === []) {
            $this->line('No backups in '.$backups->directory());

            return self::FAILURE;
        }

        $this->table(
            ['File', 'Label', 'Size'],
            array_map(
                fn (array $b) => [$b['name'], $b['label'] ?? '-', number_format($b['size'] / 1024).' KB'],
                $all,
            ),
        );
        $this->line('Restore one with: php artisan db:restore <file> (or "latest")');

        return self::SUCCESS;
    }
}
