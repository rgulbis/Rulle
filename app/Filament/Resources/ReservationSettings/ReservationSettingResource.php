<?php

namespace App\Filament\Resources\ReservationSettings;

use App\Filament\Resources\ReservationSettings\Pages\ManageReservationSettings;
use App\Models\ReservationSetting;
use App\Models\SubscriptionType;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReservationSettingResource extends Resource
{
    protected static ?string $model = ReservationSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Reservation Pricing';

    protected static ?string $modelLabel = 'reservation pricing';

    protected static ?string $pluralModelLabel = 'reservation pricing';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // A reservation is charged through Stripe, which refuses a
                // payment under €0.50 — so the rate has to make even the
                // smallest booking (shortest slot, smallest group) reach it.
                TextInput::make('price_cents_per_person_per_hour')
                    ->label('Price per person, per hour (EUR)')
                    ->required()
                    ->numeric()
                    ->minValue(SubscriptionType::MIN_PRICE_CENTS / 100)
                    // A typo (an extra zero, a misplaced decimal) shouldn't
                    // be able to put an absurd rate on every booking.
                    ->maxValue(ReservationSetting::MAX_PRICE_CENTS_PER_PERSON_PER_HOUR / 100)
                    ->step(0.01)
                    ->prefix('€')
                    ->formatStateUsing(fn (?int $state) => $state !== null ? $state / 100 : null)
                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                            $smallest = (new ReservationSetting([
                                'price_cents_per_person_per_hour' => (int) round(((float) $value) * 100),
                            ]))->priceFor((int) $get('min_duration_minutes'), (int) $get('min_group_size'));

                            if ($smallest < SubscriptionType::MIN_PRICE_CENTS) {
                                $fail('At this price the smallest booking ('.(int) $get('min_group_size').' people for '.(int) $get('min_duration_minutes').' minutes) would cost €'.number_format($smallest / 100, 2).', under the €'.number_format(SubscriptionType::MIN_PRICE_CENTS / 100, 2).' Stripe accepts.');
                            }
                        },
                    ]),

                TextInput::make('min_group_size')
                    ->label('Minimum group size')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(ReservationSetting::MAX_GROUP_SIZE_LIMIT),

                TextInput::make('max_group_size')
                    ->label('Maximum group size')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(ReservationSetting::MAX_GROUP_SIZE_LIMIT)
                    ->gte('min_group_size')
                    ->helperText('Caps how many people a single reservation can be made for.'),

                TextInput::make('min_duration_minutes')
                    ->label('Minimum reservation length (minutes)')
                    ->required()
                    ->numeric()
                    ->minValue(15)
                    ->maxValue(ReservationSetting::MAX_DURATION_LIMIT_MINUTES),

                TextInput::make('max_duration_minutes')
                    ->label('Maximum reservation length (minutes)')
                    ->required()
                    ->numeric()
                    ->minValue(15)
                    ->maxValue(ReservationSetting::MAX_DURATION_LIMIT_MINUTES)
                    ->gte('min_duration_minutes'),

                TextInput::make('opening_time')
                    ->label('Opening time')
                    ->required()
                    ->placeholder('08:00')
                    ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')
                    ->helperText('24-hour, e.g. 08:00'),

                TextInput::make('closing_time')
                    ->label('Closing time')
                    ->required()
                    ->placeholder('23:00')
                    ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')
                    // Both are zero-padded "HH:MM", so comparing them as
                    // strings is comparing them as times. The booking and
                    // entry checks assume the park closes later the same day.
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                            if (is_string($value) && is_string($get('opening_time')) && $value <= $get('opening_time')) {
                                $fail('The park has to close after it opens (the same day).');
                            }
                        },
                    ])
                    ->helperText('24-hour, e.g. 23:00. Must be after the opening time.'),

                TextInput::make('cancellation_cutoff_hours')
                    ->label('Cancellation refund cutoff (hours)')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(ReservationSetting::MAX_CANCELLATION_CUTOFF_HOURS)
                    ->helperText('A paid reservation cancelled at least this many hours before its start gets refunded; cancelling closer to the start still frees the slot but forfeits the payment.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('price_cents_per_person_per_hour')
                    ->label('Price per person/hour')
                    ->formatStateUsing(fn (int $state) => number_format($state / 100, 2).' €'),
                TextColumn::make('min_group_size')->label('Min group size'),
                TextColumn::make('max_group_size')->label('Max group size'),
                TextColumn::make('min_duration_minutes')->label('Min length (min)'),
                TextColumn::make('max_duration_minutes')->label('Max length (min)'),
                TextColumn::make('opening_time')->label('Opens'),
                TextColumn::make('closing_time')->label('Closes'),
                TextColumn::make('cancellation_cutoff_hours')->label('Refund cutoff (hrs)'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageReservationSettings::route('/'),
        ];
    }
}
