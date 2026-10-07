<?php

namespace App\Filament\Resources\Reservations\Tables;

use App\Models\Reservation;
use App\Support\Payments\RefundOutcome;
use App\Support\Payments\Refunds;
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
                    ->label('Accepted')
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
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'pending' => 'Pending',
                        'cancelled' => 'Cancelled',
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
                        //
                        // The refund is decided by what *this* click changed:
                        // a conditional UPDATE, so if the customer cancelled
                        // (and was refunded) a moment earlier it matches no
                        // row and nobody is refunded twice.
                        $wasPaid = Reservation::whereKey($record->id)->where('status', 'active')->update(['status' => 'cancelled']) > 0;

                        if (! $wasPaid) {
                            Reservation::whereKey($record->id)->where('status', 'pending')->update(['status' => 'cancelled']);
                        }

                        if (! $wasPaid || ! $record->starts_at->isFuture()) {
                            return;
                        }

                        $record->refresh();

                        // Recorded before Stripe is called, and retried by
                        // `payments:retry-refunds` if Stripe doesn't confirm.
                        match (app(Refunds::class)->refundInFull($record)) {
                            RefundOutcome::Refunded => Notification::make()->title('Cancelled and refunded')->success()->send(),
                            RefundOutcome::Pending => Notification::make()
                                ->title('Cancelled, refund not confirmed yet')
                                ->body('Stripe did not confirm the refund. It is recorded and will be retried automatically.')
                                ->warning()
                                ->send(),
                            RefundOutcome::NothingToRefund => Notification::make()
                                ->title('Cancelled, nothing to refund')
                                ->body('No Stripe payment is on record for this reservation.')
                                ->warning()
                                ->send(),
                        };
                    }),
            ]);
    }
}
