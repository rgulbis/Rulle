<?php

namespace App\Filament\Resources\SubscriptionTypes\Tables;

use App\Filament\Resources\SubscriptionTypes\Actions\DeletePlanAction;
use App\Filament\Resources\SubscriptionTypes\Actions\SyncToStripeAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubscriptionTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),

                TextColumn::make('price_cents')
                    ->label('Price')
                    ->formatStateUsing(fn (int $state) => number_format($state / 100, 2).' €')
                    ->sortable(),

                TextColumn::make('billing_interval')
                    ->badge(),

                TextColumn::make('visit_limit')
                    ->label('Visit limit')
                    ->getStateUsing(fn ($record) => match (true) {
                        $record->unlimited_entries => 'Unlimited (same day)',
                        $record->visit_limit !== null => (string) $record->visit_limit,
                        default => '—',
                    }),

                IconColumn::make('active')
                    ->boolean(),

                TextColumn::make('stripe_price_id')
                    ->label('Synced to Stripe')
                    ->getStateUsing(fn ($record) => $record->needsStripeSync() ? 'No' : 'Yes')
                    ->badge()
                    ->color(fn (string $state) => $state === 'Yes' ? 'success' : 'warning'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                SyncToStripeAction::make(),
                EditAction::make(),
                DeletePlanAction::make(),
            ]);
    }
}
