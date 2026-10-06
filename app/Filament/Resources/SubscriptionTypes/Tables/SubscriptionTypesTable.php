<?php

namespace App\Filament\Resources\SubscriptionTypes\Tables;

use App\Models\SubscriptionType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

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
                    DeleteBulkAction::make()
                        ->before(function (DeleteBulkAction $action, Collection $records) {
                            $sold = $records->filter(fn (SubscriptionType $type) => $type->hasSales());

                            if ($sold->isNotEmpty()) {
                                Notification::make()
                                    ->title('These plans have been sold and can only be deactivated')
                                    ->body($sold->pluck('name')->implode(', '))
                                    ->danger()
                                    ->send();
                                $action->cancel();
                            }
                        }),
                ]),
            ]);
    }
}
