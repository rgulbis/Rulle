<?php

namespace App\Filament\Resources\SubscriptionTypes\Schemas;

use App\Models\SubscriptionType;
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
                    ->label('Name (English)')
                    ->required()
                    ->maxLength(255),

                TextInput::make('name_lv')
                    ->label('Name (Latvian)')
                    ->helperText('Leave blank to show the English name on the Latvian site.')
                    ->maxLength(255),

                Textarea::make('description')
                    ->label('Description (English)')
                    ->columnSpanFull(),

                Textarea::make('description_lv')
                    ->label('Description (Latvian)')
                    ->helperText('Leave blank to show the English description on the Latvian site.')
                    ->columnSpanFull(),

                TextInput::make('price_cents')
                    ->label('Price (EUR)')
                    ->required()
                    ->numeric()
                    // Stripe won't create a Price below €0.50.
                    ->minValue(0.5)
                    ->prefix('€')
                    ->formatStateUsing(fn (?int $state) => $state !== null ? $state / 100 : null)
                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),

                Select::make('billing_interval')
                    ->label('Billing')
                    ->required()
                    ->live()
                    // Once sold, a plan's billing type is fixed (see
                    // SubscriptionType::booted()) — make a new plan instead.
                    ->disabled(fn (?SubscriptionType $record) => $record?->hasSales() ?? false)
                    ->helperText(fn (?SubscriptionType $record) => $record?->hasSales() ? 'Locked: this plan has been sold. Create a new plan to change the billing type.' : null)
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
                    ->integer()
                    ->minValue(1)
                    // A one-time pass that isn't unlimited-entry needs a
                    // visit count, or the buyer pays and gets zero entries.
                    ->required(fn ($get) => $get('billing_interval') === 'one_time' && ! $get('unlimited_entries'))
                    ->helperText('Number of visits granted. Ignored for unlimited-entry or recurring plans.')
                    ->hidden(fn ($get) => $get('unlimited_entries')),

                Toggle::make('active')
                    ->default(true),
            ]);
    }
}
