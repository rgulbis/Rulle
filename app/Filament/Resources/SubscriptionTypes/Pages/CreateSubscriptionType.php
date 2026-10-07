<?php

namespace App\Filament\Resources\SubscriptionTypes\Pages;

use App\Filament\Resources\SubscriptionTypes\Actions\SyncToStripeAction;
use App\Filament\Resources\SubscriptionTypes\Schemas\SubscriptionTypeForm;
use App\Filament\Resources\SubscriptionTypes\SubscriptionTypeResource;
use App\Models\SubscriptionType;
use Filament\Resources\Pages\CreateRecord;

class CreateSubscriptionType extends CreateRecord
{
    protected static string $resource = SubscriptionTypeResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return SubscriptionTypeForm::normalise($data);
    }

    protected function afterCreate(): void
    {
        /** @var SubscriptionType $plan */
        $plan = $this->record;

        SyncToStripeAction::afterSave($plan);
    }
}
