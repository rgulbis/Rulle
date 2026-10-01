<?php

namespace App\Filament\Widgets;

use App\Models\Purchase;
use App\Models\Reservation;
use App\Support\StripeRevenue;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RevenueStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    /**
     * Money comes from three independent sources that nothing else
     * combines: one-time passes and reservations are local (`purchases`,
     * `reservations`), while recurring subscriptions are billed and
     * invoiced entirely on Stripe's side — Cashier doesn't mirror invoice
     * amounts into a local table, so that figure is fetched live (and
     * cached — see StripeRevenue).
     */
    protected function getStats(): array
    {
        $passes = Purchase::whereIn('status', ['active', 'used_up']);
        $refundedPasses = Purchase::where('status', 'refunded')->sum('price_cents');
        $reservations = Reservation::where('status', 'active');

        $passesTotal = (clone $passes)->sum('price_cents');
        $passesThisMonth = (clone $passes)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('price_cents');

        $reservationsTotal = (clone $reservations)->sum('price_cents');
        $reservationsThisMonth = (clone $reservations)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('price_cents');

        $subscriptionsTotal = StripeRevenue::paidSubscriptionRevenueCents();
        $subscriptionsThisMonth = StripeRevenue::paidSubscriptionRevenueCents(
            (int) now()->startOfMonth()->timestamp,
            (int) now()->endOfMonth()->timestamp,
        );

        $total = $passesTotal + $reservationsTotal + $subscriptionsTotal;
        $thisMonth = $passesThisMonth + $reservationsThisMonth + $subscriptionsThisMonth;

        return [
            Stat::make('Total revenue', number_format($total / 100, 2).' €')
                ->description('All time, across passes, subscriptions, and reservations')
                ->color('primary')
                ->icon(Heroicon::OutlinedBanknotes),
            Stat::make('This month', number_format($thisMonth / 100, 2).' €')
                ->color('success')
                ->icon(Heroicon::OutlinedChartBar),
            Stat::make('One-time passes', number_format($passesTotal / 100, 2).' €')
                ->description('All time, excluding refunds')
                ->color('info')
                ->icon(Heroicon::OutlinedTicket),
            Stat::make('Subscriptions', number_format($subscriptionsTotal / 100, 2).' €')
                ->description('All time, from Stripe')
                ->color('warning')
                ->icon(Heroicon::OutlinedCreditCard),
            Stat::make('Reservations', number_format($reservationsTotal / 100, 2).' €')
                ->description('All time')
                ->color('info')
                ->icon(Heroicon::OutlinedCalendarDays),
            Stat::make('Refunded', number_format($refundedPasses / 100, 2).' €')
                ->color('danger')
                ->icon(Heroicon::OutlinedArrowUturnLeft),
        ];
    }
}
