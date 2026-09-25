<?php

namespace App\Filament\Widgets;

use App\Support\CheckInOccupancy;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ActiveRidersStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '10s';

    protected function getStats(): array
    {
        return [
            Stat::make('Active riders', CheckInOccupancy::currentlyCheckedInCount())
                ->description('Currently checked in')
                ->color('success'),
        ];
    }
}
