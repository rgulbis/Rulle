<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

#[Signature('db:backup {--prefix=manual : Name prefix; retention is applied per prefix} {--keep=14 : How many backups with this prefix to keep}')]
#[Description('Write a consistent snapshot of the SQLite database to storage/app/backups')]
class DbBackup extends Command
{
    /**
     * Prints only the new file's path on stdout (nothing at all when there was
     * nothing to back up), so deploy scripts can capture it.
     */
    public function handle(): int
    {
        $database = config('database.connections.sqlite.database');

        if (config('database.default') !== 'sqlite' || ! is_string($database) || ! is_file($database) || filesize($database) === 0) {
            return self::SUCCESS;
        }

        $directory = storage_path('app/backups');
        File::ensureDirectoryExists($directory);

        $prefix = preg_replace('/[^a-z0-9_-]/i', '', (string) $this->option('prefix')) ?: 'manual';
        $path = "{$directory}/{$prefix}-".now()->format('Ymd-His').'.sqlite';

        // VACUUM INTO is SQLite's own online backup: a consistent copy even
        // in WAL mode with other connections writing, unlike copying the file.
        DB::connection()->statement('VACUUM INTO ?', [$path]);

        $old = collect(File::glob("{$directory}/{$prefix}-*.sqlite"))->sort()->values();
        $old->slice(0, max(0, $old->count() - max(1, (int) $this->option('keep'))))
            ->each(fn (string $file) => File::delete($file));

        $this->line($path);

        return self::SUCCESS;
    }
}
