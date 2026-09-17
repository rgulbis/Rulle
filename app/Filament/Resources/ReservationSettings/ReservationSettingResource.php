<?php

namespace App\Filament\Resources\ReservationSettings;

use App\Filament\Resources\ReservationSettings\Pages\ManageReservationSettings;
use App\Models\ReservationSetting;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
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
                TextInput::make('price_cents_per_person_per_hour')
                    ->label('Price per person, per hour (EUR)')
                    ->required()
                    ->numeric()
                    ->prefix('€')
                    ->formatStateUsing(fn (?int $state) => $state !== null ? $state / 100 : null)
                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),

                TextInput::make('min_group_size')
                    ->label('Minimum group size')
                    ->required()
                    ->numeric()
                    ->minValue(1),

                TextInput::make('min_duration_minutes')
                    ->label('Minimum reservation length (minutes)')
                    ->required()
                    ->numeric()
                    ->minValue(15),

                TextInput::make('max_duration_minutes')
                    ->label('Maximum reservation length (minutes)')
                    ->required()
                    ->numeric()
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
                    ->helperText('24-hour, e.g. 23:00'),

                TextInput::make('cancellation_cutoff_hours')
                    ->label('Cancellation refund cutoff (hours)')
                    ->required()
                    ->numeric()
                    ->minValue(0)
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
