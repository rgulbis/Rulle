<?php

namespace App\Filament\Resources\SubscriptionTypes\Pages;

use App\Filament\Resources\SubscriptionTypes\Actions\DeletePlanAction;
use App\Filament\Resources\SubscriptionTypes\Actions\SyncToStripeAction;
use App\Filament\Resources\SubscriptionTypes\Schemas\SubscriptionTypeForm;
use App\Filament\Resources\SubscriptionTypes\SubscriptionTypeResource;
use App\Models\SubscriptionType;
use Filament\Resources\Pages\EditRecord;

class EditSubscriptionType extends EditRecord
{
    protected static string $resource = SubscriptionTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SyncToStripeAction::make(),
            DeletePlanAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var SubscriptionType $plan */
        $plan = $this->record;

        // The billing type is locked (and so absent from the form data) once
        // a plan has sales; the stored one is what applies then.
        return SubscriptionTypeForm::normalise($data, $plan->billing_interval);
    }

    protected function afterSave(): void
    {
        /** @var SubscriptionType $plan */
        $plan = $this->record;

        SyncToStripeAction::afterSave($plan);
    }
}
