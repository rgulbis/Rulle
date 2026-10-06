<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

#[Signature('db:restore {file : Path of a backup made by db:backup} {--force : Required — this replaces the live database}')]
#[Description('Replace the SQLite database with a backup (keeps a safety copy of the current one first)')]
class DbRestore extends Command
{
    public function handle(): int
    {
        $backup = (string) $this->argument('file');
        $database = config('database.connections.sqlite.database');

        if (! $this->option('force')) {
            $this->error('Refusing to replace the live database without --force.');

            return self::FAILURE;
        }

        if (! is_string($database) || ! is_file($backup)) {
            $this->error('Backup file not found.');

            return self::FAILURE;
        }

        // Safety copy of what's being replaced, so a restore is itself undoable.
        $this->callSilently('db:backup', ['--prefix' => 'before-restore', '--keep' => 5]);

        DB::disconnect();
        File::delete([$database.'-wal', $database.'-shm']);
        File::copy($backup, $database);

        $this->info("Restored {$backup}. Restart the app containers.");

        return self::SUCCESS;
    }
}
