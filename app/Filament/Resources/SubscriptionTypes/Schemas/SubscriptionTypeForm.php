<?php

namespace App\Filament\Resources\SubscriptionTypes\Schemas;

use App\Models\SubscriptionType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
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
                    ->minValue(SubscriptionType::MIN_PRICE_CENTS / 100)
                    ->maxValue(SubscriptionType::MAX_PRICE_CENTS / 100)
                    ->step(0.01)
                    ->prefix('€')
                    ->helperText('Between €'.number_format(SubscriptionType::MIN_PRICE_CENTS / 100, 2).' (Stripe doesn\'t accept smaller payments) and €'.number_format(SubscriptionType::MAX_PRICE_CENTS / 100, 2).'.')
                    ->formatStateUsing(fn (?int $state) => $state !== null ? $state / 100 : null)
                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),

                // What was sold under one billing type can't be re-sold under
                // another: subscribers are on Stripe Prices of that type, and
                // passes already bought count visits. The model refuses the
                // change as well; this just doesn't offer it.
                Select::make('billing_interval')
                    ->label('Billing')
                    ->required()
                    ->options([
                        'one_time' => 'One-time',
                        'month' => 'Monthly',
                        'year' => 'Yearly',
                    ])
                    ->live()
                    ->disabled(fn (?SubscriptionType $record): bool => $record?->hasSales() ?? false)
                    ->helperText(fn (?SubscriptionType $record): ?string => $record?->hasSales()
                        ? 'Locked: this plan already has sales or subscribers. Create a new plan to sell it differently.'
                        : null),

                Toggle::make('unlimited_entries')
                    ->label('Unlimited entries on the day of purchase')
                    ->helperText('For one-time plans: usable any number of times, but only on the day it was bought.')
                    ->live()
                    ->hidden(fn (Get $get): bool => $get('billing_interval') !== 'one_time'),

                TextInput::make('visit_limit')
                    ->label('Visit limit')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->required()
                    ->helperText('Number of visits a purchase grants.')
                    ->visible(fn (Get $get): bool => $get('billing_interval') === 'one_time' && ! $get('unlimited_entries')),

                Toggle::make('active')
                    ->default(true),
            ]);
    }

    /**
     * Drops what doesn't apply to the plan's billing type, so a plan that
     * was one-time and becomes recurring (before it has sales) doesn't keep a
     * visit limit that a hidden form field can no longer show or clear.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalise(array $data, ?string $storedInterval = null): array
    {
        $interval = $data['billing_interval'] ?? $storedInterval;

        if ($interval !== 'one_time') {
            $data['unlimited_entries'] = false;
            $data['visit_limit'] = null;
        } elseif ($data['unlimited_entries'] ?? false) {
            $data['visit_limit'] = null;
        }

        return $data;
    }
}
