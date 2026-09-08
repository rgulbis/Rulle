<?php

namespace App\Filament\Resources\SubscriptionTypes\Pages;

use App\Filament\Resources\SubscriptionTypes\SubscriptionTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSubscriptionType extends EditRecord
{
    protected static string $resource = SubscriptionTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
