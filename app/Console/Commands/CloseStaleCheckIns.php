<?php

namespace App\Console\Commands;

use App\Events\OccupancyUpdated;
use App\Support\CheckInOccupancy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('checkins:close-stale')]
#[Description('Check out everyone still marked as inside since before the park last closed')]
class CloseStaleCheckIns extends Command
{
    public function handle(): int
    {
        $closed = CheckInOccupancy::closeStaleCheckIns();

        if ($closed > 0) {
            OccupancyUpdated::dispatch();
            $this->info("Checked out {$closed} rider(s) left over from before closing.");
        }

        return self::SUCCESS;
    }
}
