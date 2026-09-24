<?php

namespace App\Filament\Resources\Purchases\Tables;

use App\Models\Purchase;
use App\Support\StripeRefunds;
use Filament\Actions\Action;
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
                        'refunded' => 'danger',
                        default => 'gray',
                    }),
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
                        'refunded' => 'Refunded',
                    ]),
            ])
            ->recordActions([
                Action::make('refund')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('This issues a real Stripe refund for what the customer paid and revokes their entry from this pass.')
                    ->visible(fn (Purchase $record) => $record->status === 'active')
                    ->action(function (Purchase $record) {
                        StripeRefunds::refundCheckoutSession($record->stripe_checkout_session_id);

                        $record->update(['status' => 'refunded']);
                    }),
            ]);
    }
}
