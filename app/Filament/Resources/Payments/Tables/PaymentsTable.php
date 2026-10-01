<?php

namespace App\Filament\Resources\Payments\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->badge()
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
                    ->label('What'),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => number_format($state / 100, 2).' €')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    // Each source has its own status vocabulary (Stripe's
                    // subscription statuses, this app's purchase/reservation
                    // ones) — grouped by what they mean, not where they're
                    // from, so the colour is still meaningful across types.
                    ->color(fn (string $state): string => match ($state) {
                        'active', 'trialing', 'used_up' => 'success',
                        'pending', 'incomplete', 'past_due' => 'warning',
                        'refunded', 'cancelled', 'canceled', 'incomplete_expired', 'unpaid' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('Y-m-d H:i')
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
            ]);
    }
}
