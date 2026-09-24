<?php

namespace App\Filament\Resources\Reservations\Tables;

use App\Models\Reservation;
use App\Support\StripeRefunds;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReservationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Reserved by')
                    ->searchable(),
                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label('Ends')
                    ->dateTime('H:i')
                    ->sortable(),
                TextColumn::make('group_size')
                    ->label('Group size'),
                TextColumn::make('participants_count')
                    ->label('Named')
                    ->counts('participants'),
                TextColumn::make('price_cents')
                    ->label('Price')
                    ->formatStateUsing(fn (int $state) => number_format($state / 100, 2).' €'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'pending' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'pending' => 'Pending',
                        'cancelled' => 'Cancelled',
                    ]),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->requiresConfirmation()
                    ->color('danger')
                    ->visible(fn (Reservation $record) => $record->status !== 'cancelled')
                    ->action(function (Reservation $record) {
                        // Unlike a customer cancelling their own reservation,
                        // an admin cancellation is never the customer's
                        // fault (double-booking cleanup, park closure,
                        // etc.) — so a still-upcoming paid booking is always
                        // refunded here, without the cancellation-cutoff
                        // grace window that only exists to discourage
                        // last-minute customer-initiated cancellations.
                        if ($record->status === 'active' && $record->starts_at->isFuture()) {
                            StripeRefunds::refundCheckoutSession($record->stripe_checkout_session_id);
                        }

                        $record->update(['status' => 'cancelled']);
                    }),
            ]);
    }
}
