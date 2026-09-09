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

                Toggle::make('unlimited_entries')
                    ->label('Unlimited entries on the day of purchase')
                    ->helperText('For one-time plans: usable any number of times, but only on the day it was bought.')
                    ->live(),

                TextInput::make('visit_limit')
                    ->label('Visit limit')
                    ->numeric()
                    ->helperText('Number of visits granted. Ignored for unlimited-entry or recurring plans.')
                    ->hidden(fn ($get) => $get('unlimited_entries')),

                Toggle::make('active')
                    ->default(true),
            ]);
    }
}
