<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Filament\Support\Labels;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->icon(fn (string $state): Heroicon => match ($state) {
                        'purchase' => Heroicon::OutlinedTicket,
                        'reservation' => Heroicon::OutlinedCalendarDays,
                        'subscription' => Heroicon::OutlinedCreditCard,
                        default => Heroicon::OutlinedBanknotes,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'purchase' => 'gray',
                        'reservation' => 'info',
                        'subscription' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'purchase' => __('One-time pass'),
                        'reservation' => __('Reservation'),
                        'subscription' => __('Subscription'),
                        default => $state,
                    })
                    ->label(__('Type')),
                TextColumn::make('user.name')
                    ->label(__('Customer'))
                    ->searchable(),
                TextColumn::make('description')
                    ->label(__('What'))
                    ->color('gray'),
                TextColumn::make('amount_cents')
                    ->label(__('Amount'))
                    ->formatStateUsing(fn (int $state): string => number_format($state / 100, 2).' €')
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Labels::humanise($state))
                    // Each source has its own status vocabulary (Stripe's
                    // subscription statuses, this app's purchase/reservation
                    // ones) — grouped by what they mean, not where they're
                    // from, so the colour is still meaningful across types.
                    ->icon(fn (string $state): Heroicon => match ($state) {
                        'active', 'trialing', 'used_up' => Heroicon::OutlinedCheckCircle,
                        'pending', 'incomplete', 'past_due' => Heroicon::OutlinedClock,
                        'cancelled', 'canceled', 'incomplete_expired', 'unpaid' => Heroicon::OutlinedXCircle,
                        default => Heroicon::OutlinedClock,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'active', 'trialing', 'used_up' => 'success',
                        'pending', 'incomplete', 'past_due' => 'warning',
                        'cancelled', 'canceled', 'incomplete_expired', 'unpaid' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('payment_status')
                    ->label(__('Payment'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Labels::humanise($state))
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'refunded', 'partially_refunded' => 'info',
                        'refund_failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('refunded_cents')
                    ->label(__('Refunded'))
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? '—' : number_format($state / 100, 2).' €')
                    ->color('gray'),
                TextColumn::make('created_at')
                    ->label(__('Date'))
                    ->dateTime('Y-m-d H:i')
                    ->color('gray')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                // On by default: an abandoned or still-open checkout isn't a
                // payment, and (with a reservation hold counted each time)
                // would drown the real ones. Switch it off to see them.
                Filter::make('hide_unpaid')
                    ->label(__('Hide unpaid'))
                    ->toggle()
                    ->default()
                    ->query(fn ($query) => $query->where('payment_status', '!=', 'unpaid')),
                SelectFilter::make('type')
                    ->label(__('Type'))
                    ->options([
                        'purchase' => __('One-time passes'),
                        'reservation' => __('Reservations'),
                        'subscription' => __('Subscriptions'),
                    ]),
                SelectFilter::make('payment_status')
                    ->label(__('Payment'))
                    ->options(Labels::paymentStatuses()),
            ]);
    }
}
