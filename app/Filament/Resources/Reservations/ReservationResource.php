<?php

namespace App\Filament\Resources\Reservations;

use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Filament\Resources\Reservations\Tables\ReservationsTable;
use App\Models\Reservation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ReservationResource extends Resource
{
    protected static ?string $model = Reservation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function getNavigationLabel(): string
    {
        return __('Reservations');
    }

    public static function getModelLabel(): string
    {
        return __('reservation');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reservations');
    }

    // Customers create their own reservations through checkout — this
    // resource is oversight only, not a way to manually create one (which
    // wouldn't have gone through payment).
    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return ReservationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReservations::route('/'),
        ];
    }
}
