<?php

namespace App\Filament\Resources\SubscriptionTypes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
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
                    ->placeholder('—'),

                IconColumn::make('active')
                    ->boolean(),

                TextColumn::make('stripe_price_id')
                    ->label('Synced to Stripe')
                    ->formatStateUsing(fn (?string $state) => $state ? 'Yes' : 'No')
                    ->badge()
                    ->color(fn (?string $state) => $state ? 'success' : 'gray'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
