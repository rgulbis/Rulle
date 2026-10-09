<?php

namespace App\Filament\Widgets;

use App\Support\CheckInOccupancy;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ActiveRidersStats extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    // Half-width (the dashboard grid is 2 columns) so this sits beside
    // CheckInsByHourChart instead of on its own full-width row - the two
    // are the same "what's happening right now" story told two ways.
    protected int|string|array $columnSpan = 1;

    protected ?string $pollingInterval = '10s';

    protected function getStats(): array
    {
        return [
            Stat::make(__('Active riders'), CheckInOccupancy::currentlyCheckedInCount())
                ->description(__('Currently checked in'))
                ->color('success')
                ->icon(Heroicon::OutlinedUserGroup),
        ];
    }
}
