<?php

namespace App\Filament\Resources\Purchases;

use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Filament\Resources\Purchases\Tables\PurchasesTable;
use App\Models\Purchase;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PurchaseResource extends Resource
{
    protected static ?string $model = Purchase::class;

    public static function getNavigationLabel(): string
    {
        return __('One-Time Passes');
    }

    public static function getModelLabel(): string
    {
        return __('purchase');
    }

    public static function getPluralModelLabel(): string
    {
        return __('purchases');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    // Customers create these through checkout - this resource is oversight
    // (and refunding) only, not a way to manually create or edit one (which
    // wouldn't have gone through payment).
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return PurchasesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchases::route('/'),
        ];
    }
}
