<?php

namespace App\Filament\Resources\Payments\Tables;

use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
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
                        'purchase' => 'One-time pass',
                        'reservation' => 'Reservation',
                        'subscription' => 'Subscription',
                        default => $state,
                    }),
                TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable(),
                TextColumn::make('description')
                    ->label('What')
                    ->color('gray'),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => number_format($state / 100, 2).' €')
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
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
                    ->label('Payment')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->ucfirst()->toString())
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'refunded', 'partially_refunded' => 'info',
                        'refund_failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('refunded_cents')
                    ->label('Refunded')
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? '—' : number_format($state / 100, 2).' €')
                    ->color('gray'),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('Y-m-d H:i')
                    ->color('gray')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'purchase' => 'One-time passes',
                        'reservation' => 'Reservations',
                        'subscription' => 'Subscriptions',
                    ]),
                SelectFilter::make('payment_status')
                    ->label('Payment')
                    ->options([
                        'unpaid' => 'Unpaid',
                        'paid' => 'Paid',
                        'refunded' => 'Refunded',
                        'partially_refunded' => 'Partially refunded',
                        'refund_failed' => 'Refund failed',
                    ]),
            ]);
    }
}
