<?php

namespace App\Filament\Resources\SubscriptionTypes\Pages;

use App\Filament\Resources\SubscriptionTypes\SubscriptionTypeResource;
use App\Models\SubscriptionType;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSubscriptionType extends EditRecord
{
    protected static string $resource = SubscriptionTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (DeleteAction $action, SubscriptionType $record) {
                    if ($record->hasSales()) {
                        Notification::make()
                            ->title('This plan has been sold — deactivate it instead of deleting it.')
                            ->danger()
                            ->send();
                        $action->cancel();
                    }
                }),
        ];
    }
}
