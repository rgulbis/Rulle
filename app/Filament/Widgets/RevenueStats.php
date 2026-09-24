<?php

namespace App\Filament\Widgets;

use App\Models\Purchase;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RevenueStats extends StatsOverviewWidget
{
    /**
     * One-time passes only — recurring subscriptions are billed and
     * invoiced entirely on Stripe's side (Cashier doesn't mirror invoice
     * amounts into a local table), so this is a partial financial picture,
     * not the whole one.
     */
    protected function getStats(): array
    {
        $collected = Purchase::whereIn('status', ['active', 'used_up']);
        $refunded = Purchase::where('status', 'refunded');

        $total = (clone $collected)->sum('price_cents');
        $thisMonth = (clone $collected)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('price_cents');

        return [
            Stat::make('One-time pass revenue', number_format($total / 100, 2).' €')
                ->description('All time, excluding refunds'),
            Stat::make('This month', number_format($thisMonth / 100, 2).' €')
                ->color('success'),
            Stat::make('Refunded', number_format($refunded->sum('price_cents') / 100, 2).' €')
                ->color('danger'),
        ];
    }
}
