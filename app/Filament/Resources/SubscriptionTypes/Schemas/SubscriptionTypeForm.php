<?php

namespace App\Filament\Resources\SubscriptionTypes\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SubscriptionTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                Textarea::make('description')
                    ->columnSpanFull(),

                TextInput::make('price_cents')
                    ->label('Price (EUR)')
                    ->required()
                    ->numeric()
                    ->prefix('€')
                    ->formatStateUsing(fn (?int $state) => $state !== null ? $state / 100 : null)
                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),

                Select::make('billing_interval')
                    ->label('Billing')
                    ->required()
                    ->options([
                        'one_time' => 'One-time',
                        'month' => 'Monthly',
                        'year' => 'Yearly',
                    ]),

                TextInput::make('visit_limit')
                    ->label('Visit limit (one-time plans only)')
                    ->numeric()
                    ->helperText('Number of visits granted. Leave blank for unlimited/recurring plans.'),

                Toggle::make('active')
                    ->default(true),
            ]);
    }
}
