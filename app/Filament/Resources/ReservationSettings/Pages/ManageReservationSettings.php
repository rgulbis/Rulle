<?php

namespace App\Filament\Resources\ReservationSettings\Pages;

use App\Filament\Resources\ReservationSettings\ReservationSettingResource;
use App\Models\ReservationSetting;
use Filament\Resources\Pages\ManageRecords;

class ManageReservationSettings extends ManageRecords
{
    protected static string $resource = ReservationSettingResource::class;

    // There's only ever the one pricing rate, seeded via
    // ReservationSetting::current() — no create/delete, just editing it.
    protected function getHeaderActions(): array
    {
        return [];
    }

    public function mount(): void
    {
        ReservationSetting::current();

        parent::mount();
    }
}
