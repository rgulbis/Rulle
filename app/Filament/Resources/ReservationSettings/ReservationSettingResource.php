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

    public static function getNavigationLabel(): string
    {
        return __('Reservation Pricing');
    }

    public static function getModelLabel(): string
    {
        return __('reservation pricing');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reservation pricing');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // A reservation is charged through Stripe, which refuses a
                // payment under €0.50 - so the rate has to make even the
                // smallest booking (shortest slot, smallest group) reach it.
                TextInput::make('price_cents_per_person_per_hour')
                    ->label(__('Price per person, per hour (EUR)'))
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
                                $fail(__('At this price the smallest booking (:people people for :minutes minutes) would cost €:cost, under the €:min Stripe accepts.', [
                                    'people' => (int) $get('min_group_size'),
                                    'minutes' => (int) $get('min_duration_minutes'),
                                    'cost' => number_format($smallest / 100, 2),
                                    'min' => number_format(SubscriptionType::MIN_PRICE_CENTS / 100, 2),
                                ]));
                            }
                        },
                    ]),

                TextInput::make('min_group_size')
                    ->label(__('Minimum group size'))
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(ReservationSetting::MAX_GROUP_SIZE_LIMIT),

                TextInput::make('max_group_size')
                    ->label(__('Maximum group size'))
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(ReservationSetting::MAX_GROUP_SIZE_LIMIT)
                    ->gte('min_group_size')
                    ->helperText(__('Caps how many people a single reservation can be made for.')),

                TextInput::make('min_duration_minutes')
                    ->label(__('Minimum reservation length (minutes)'))
                    ->required()
                    ->numeric()
                    ->minValue(15)
                    ->maxValue(ReservationSetting::MAX_DURATION_LIMIT_MINUTES),

                TextInput::make('max_duration_minutes')
                    ->label(__('Maximum reservation length (minutes)'))
                    ->required()
                    ->numeric()
                    ->minValue(15)
                    ->maxValue(ReservationSetting::MAX_DURATION_LIMIT_MINUTES)
                    ->gte('min_duration_minutes'),

                TextInput::make('opening_time')
                    ->label(__('Opening time'))
                    ->required()
                    ->placeholder('08:00')
                    ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')
                    // A booking is only checked against the hours when it is
                    // made, so hours that would cut into one already paid for
                    // are refused instead of silently shortening it.
                    ->rules([
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail) {
                            $affected = ReservationSetting::reservationsOutsideHours($value, null);

                            if ($affected > 0) {
                                $fail(trans_choice('{1} :count paid reservation still to come start before :time. Cancel or move them first.|[2,*] :count paid reservations still to come start before :time. Cancel or move them first.', $affected, ['time' => $value]));
                            }
                        },
                    ])
                    ->helperText(__('24-hour, e.g. 08:00')),

                TextInput::make('closing_time')
                    ->label(__('Closing time'))
                    ->required()
                    ->placeholder('23:00')
                    ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')
                    // Both are zero-padded "HH:MM", so comparing them as
                    // strings is comparing them as times. The booking and
                    // entry checks assume the park closes later the same day.
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                            if (is_string($value) && is_string($get('opening_time')) && $value <= $get('opening_time')) {
                                $fail(__('The park has to close after it opens (the same day).'));
                            }
                        },
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail) {
                            $affected = ReservationSetting::reservationsOutsideHours(null, $value);

                            if ($affected > 0) {
                                $fail(trans_choice('{1} :count paid reservation still to come end after :time. Cancel or move them first.|[2,*] :count paid reservations still to come end after :time. Cancel or move them first.', $affected, ['time' => $value]));
                            }
                        },
                    ])
                    ->helperText(__('24-hour, e.g. 23:00. Must be after the opening time.')),

                TextInput::make('cancellation_cutoff_hours')
                    ->label(__('Cancellation refund cutoff (hours)'))
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(ReservationSetting::MAX_CANCELLATION_CUTOFF_HOURS)
                    ->helperText(__('A paid reservation cancelled at least this many hours before its start gets refunded; cancelling closer to the start still frees the slot but forfeits the payment.')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('price_cents_per_person_per_hour')
                    ->label(__('Price per person/hour'))
                    ->formatStateUsing(fn (int $state) => number_format($state / 100, 2).' €'),
                TextColumn::make('min_group_size')->label(__('Min group size')),
                TextColumn::make('max_group_size')->label(__('Max group size')),
                TextColumn::make('min_duration_minutes')->label(__('Min length (min)')),
                TextColumn::make('max_duration_minutes')->label(__('Max length (min)')),
                TextColumn::make('opening_time')->label(__('Opens')),
                TextColumn::make('closing_time')->label(__('Closes')),
                TextColumn::make('cancellation_cutoff_hours')->label(__('Refund cutoff (hrs)')),
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
