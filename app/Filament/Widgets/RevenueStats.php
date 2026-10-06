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
        // Revenue is what the money says, not what the booking's lifecycle
        // status says: a cancelled reservation whose payment was kept (too
        // late for a refund) is still earned money, and a refunded one isn't.
        $passes = Purchase::where('payment_status', 'paid');
        $reservations = Reservation::where('payment_status', 'paid');
        $refunded = Purchase::sum('refunded_cents') + Reservation::sum('refunded_cents');
        $refundsOwed = Purchase::whereIn('payment_status', ['refund_pending', 'refund_failed'])->sum('price_cents')
            + Reservation::whereIn('payment_status', ['refund_pending', 'refund_failed'])->sum('price_cents');

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
                ->description('All time, net of refunds')
                ->color('info')
                ->icon(Heroicon::OutlinedTicket),
            Stat::make('Subscriptions', number_format($subscriptionsTotal / 100, 2).' €')
                ->description('All time, from Stripe')
                ->color('warning')
                ->icon(Heroicon::OutlinedCreditCard),
            Stat::make('Reservations', number_format($reservationsTotal / 100, 2).' €')
                ->description('All time, net of refunds')
                ->color('info')
                ->icon(Heroicon::OutlinedCalendarDays),
            Stat::make('Refunded', number_format($refunded / 100, 2).' €')
                ->description('Passes and reservations')
                ->color('danger')
                ->icon(Heroicon::OutlinedArrowUturnLeft),
            Stat::make('Refunds still owed', number_format($refundsOwed / 100, 2).' €')
                ->description('Pending or failed — retried by payments:retry-refunds')
                ->color($refundsOwed > 0 ? 'danger' : 'gray')
                ->icon(Heroicon::OutlinedExclamationTriangle),
        ];
    }
}
