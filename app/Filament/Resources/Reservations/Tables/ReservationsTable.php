<?php

namespace App\Filament\Resources\Reservations\Tables;

use App\Models\Reservation;
use App\Support\Payments\ReservationBooking;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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
                TextColumn::make('payment_status')
                    ->label('Payment')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'refunded' => 'info',
                        'refund_pending', 'refund_failed' => 'danger',
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
                        $outcome = app(ReservationBooking::class)->cancel($record, refundRegardless: true);

                        match ($outcome) {
                            'refund-pending' => Notification::make()->title('Cancelled — the refund failed and will be retried')->danger()->send(),
                            'cannot-cancel' => Notification::make()->title('This reservation has already started')->warning()->send(),
                            default => Notification::make()->title('Reservation cancelled')->success()->send(),
                        };
                    }),
            ]);
    }
}
