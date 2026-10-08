<?php

namespace App\Filament\Resources\SubscriptionTypes\Tables;

use App\Filament\Resources\SubscriptionTypes\Actions\DeletePlanAction;
use App\Filament\Resources\SubscriptionTypes\Actions\SyncToStripeAction;
use App\Filament\Support\Labels;
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
                    ->label(__('Name'))
                    ->searchable(),

                TextColumn::make('price_cents')
                    ->label(__('Price'))
                    ->formatStateUsing(fn (int $state) => number_format($state / 100, 2).' €')
                    ->sortable(),

                TextColumn::make('billing_interval')
                    ->label(__('Billing'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Labels::billingIntervals()[$state] ?? $state),

                TextColumn::make('visit_limit')
                    ->label(__('Visit limit'))
                    ->getStateUsing(fn ($record) => match (true) {
                        $record->unlimited_entries => __('Unlimited (same day)'),
                        $record->visit_limit !== null => (string) $record->visit_limit,
                        default => '—',
                    }),

                IconColumn::make('active')
                    ->label(__('Active'))
                    ->boolean(),

                TextColumn::make('stripe_price_id')
                    ->label(__('Synced to Stripe'))
                    ->getStateUsing(fn ($record) => $record->needsStripeSync() ? __('No') : __('Yes'))
                    ->badge()
                    ->color(fn (string $state) => $state === __('Yes') ? 'success' : 'warning'),
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
