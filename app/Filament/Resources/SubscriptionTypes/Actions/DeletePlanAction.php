<?php

namespace App\Filament\Resources\SubscriptionTypes\Actions;

use App\Models\SubscriptionType;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;

/**
 * Delete for a plan, which says no (and why) for one that has sales or
 * subscribers, instead of running into the database's refusal.
 */
class DeletePlanAction extends DeleteAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->modalDescription(__('Only possible for a plan that was never bought. A plan with sales can be deactivated instead.'));

        $this->before(function (DeletePlanAction $action, SubscriptionType $record) {
            $reason = $record->deletionBlocker();

            if ($reason === null) {
                return;
            }

            Notification::make()
                ->danger()
                ->title(__(':name can\'t be deleted', ['name' => $record->name]))
                ->body($reason)
                ->persistent()
                ->send();

            $action->cancel();
        });
    }
}
