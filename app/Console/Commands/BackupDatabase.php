<?php

namespace App\Console\Commands;

use App\Support\DatabaseBackup;
use Illuminate\Console\Command;
use RuntimeException;

class BackupDatabase extends Command
{
    protected $signature = 'db:backup
        {--label= : Tag for the file name (e.g. pre-deploy); each label is pruned separately}
        {--keep=14 : How many snapshots with this label to keep}';

    protected $description = 'Snapshot the SQLite database into storage/app/backups';

    public function handle(DatabaseBackup $backups): int
    {
        $label = $this->option('label') ?: null;
        $keep = (int) $this->option('keep');

        if ($keep < 1) {
            $this->error('--keep must be at least 1.');

            return self::FAILURE;
        }

        try {
            $path = $backups->create($label);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($backups->prune($label, $keep) as $name) {
            $this->components->twoColumnDetail('Pruned', $name);
        }

        $this->info('Backup written:');
        $this->line($path);

        return self::SUCCESS;
    }
}
