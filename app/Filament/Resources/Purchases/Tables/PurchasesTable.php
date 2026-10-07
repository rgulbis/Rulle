<?php

namespace App\Filament\Resources\Purchases\Tables;

use App\Models\Purchase;
use App\Support\Payments\RefundOutcome;
use App\Support\Payments\Refunds;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable(),
                TextColumn::make('subscriptionType.name')
                    ->label('Plan')
                    ->searchable(),
                TextColumn::make('price_cents')
                    ->label('Paid')
                    ->formatStateUsing(fn (?int $state) => $state === null ? '—' : number_format($state / 100, 2).' €')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'pending' => 'warning',
                        'cancelled' => 'danger',
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
                TextColumn::make('visits_remaining')
                    ->label('Visits left')
                    ->placeholder('—'),
                TextColumn::make('valid_date')
                    ->label('Valid on')
                    ->date()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Purchased')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'active' => 'Active',
                        'used_up' => 'Used up',
                        'cancelled' => 'Cancelled',
                        'abandoned' => 'Abandoned',
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
                Action::make('refund')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('This issues a real Stripe refund for what the customer paid and revokes their entry from this pass.')
                    ->visible(fn (Purchase $record) => $record->status === 'active')
                    ->action(function (Purchase $record) {
                        // The pass is revoked straight away; the refund is
                        // recorded before Stripe is called and retried by
                        // `payments:retry-refunds` if Stripe doesn't confirm.
                        Purchase::whereKey($record->id)->where('status', 'active')->update(['status' => 'cancelled']);

                        $outcome = app(Refunds::class)->refundInFull($record->refresh());

                        match ($outcome) {
                            RefundOutcome::Refunded => Notification::make()->title('Refunded')->success()->send(),
                            RefundOutcome::Pending => Notification::make()
                                ->title('Pass revoked, refund not confirmed yet')
                                ->body('Stripe did not confirm the refund. It is recorded and will be retried automatically.')
                                ->warning()
                                ->send(),
                            RefundOutcome::NothingToRefund => Notification::make()
                                ->title('Pass revoked, nothing to refund')
                                ->body('No Stripe payment is on record for this pass.')
                                ->warning()
                                ->send(),
                        };
                    }),
            ]);
    }
}
