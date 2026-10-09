<?php

namespace App\Filament\Widgets;

use App\Models\Purchase;
use App\Models\Reservation;
use App\Support\StripeRevenue;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class RevenueStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    /**
     * Money comes from three independent sources that nothing else
     * combines: one-time passes and reservations are local (`purchases`,
     * `reservations`), while recurring subscriptions are billed and
     * invoiced entirely on Stripe's side - Cashier doesn't mirror invoice
     * amounts into a local table, so that figure is fetched live (and
     * cached - see StripeRevenue).
     *
     * Passes and reservations are counted from `payment_status`, not their
     * lifecycle `status`: revenue is what was paid minus what was refunded,
     * so a reservation cancelled too late for a refund still counts, and a
     * refunded one nets out to zero.
     */
    protected function getStats(): array
    {
        $passes = Purchase::where('payment_status', '!=', Purchase::PAYMENT_UNPAID);
        $reservations = Reservation::where('payment_status', '!=', Reservation::PAYMENT_UNPAID);

        $thisMonth = [now()->startOfMonth(), now()->endOfMonth()];

        $passesTotal = $this->netCents($passes);
        $passesThisMonth = $this->netCents((clone $passes)->whereBetween('created_at', $thisMonth));

        $reservationsTotal = $this->netCents($reservations);
        $reservationsThisMonth = $this->netCents((clone $reservations)->whereBetween('created_at', $thisMonth));

        $refunded = (int) Purchase::sum('refunded_cents') + (int) Reservation::sum('refunded_cents');

        $subscriptionsTotal = StripeRevenue::paidSubscriptionRevenueCents();
        $subscriptionsThisMonth = StripeRevenue::paidSubscriptionRevenueCents(
            (int) now()->startOfMonth()->timestamp,
            (int) now()->endOfMonth()->timestamp,
        );

        $total = $passesTotal + $reservationsTotal + $subscriptionsTotal;
        $monthTotal = $passesThisMonth + $reservationsThisMonth + $subscriptionsThisMonth;

        return [
            Stat::make(__('Total revenue'), number_format($total / 100, 2).' €')
                ->description(__('All time, across passes, subscriptions, and reservations'))
                ->color('primary')
                ->icon(Heroicon::OutlinedBanknotes),
            Stat::make(__('This month'), number_format($monthTotal / 100, 2).' €')
                ->color('success')
                ->icon(Heroicon::OutlinedChartBar),
            Stat::make(__('One-time passes'), number_format($passesTotal / 100, 2).' €')
                ->description(__('All time, excluding refunds'))
                ->color('info')
                ->icon(Heroicon::OutlinedTicket),
            Stat::make(__('Subscriptions'), number_format($subscriptionsTotal / 100, 2).' €')
                ->description(__('All time, this app\'s customers, from Stripe'))
                ->color('warning')
                ->icon(Heroicon::OutlinedCreditCard),
            Stat::make(__('Reservations'), number_format($reservationsTotal / 100, 2).' €')
                ->description(__('All time, excluding refunds'))
                ->color('info')
                ->icon(Heroicon::OutlinedCalendarDays),
            Stat::make(__('Refunded'), number_format($refunded / 100, 2).' €')
                ->description(__('Passes and reservations'))
                ->color('danger')
                ->icon(Heroicon::OutlinedArrowUturnLeft),
        ];
    }

    /**
     * Paid minus refunded for the given (already paid-only) rows.
     *
     * @param  Builder<Purchase>|Builder<Reservation>  $query
     */
    private function netCents(Builder $query): int
    {
        return (int) (clone $query)->sum('price_cents') - (int) (clone $query)->sum('refunded_cents');
    }
}
