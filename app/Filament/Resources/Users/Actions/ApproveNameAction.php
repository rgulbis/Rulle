<?php

namespace App\Filament\Resources\Users\Actions;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Approves a requested display name — or says why it couldn't be: the name
 * is checked again at approval, because somebody else may have taken it in
 * the time the request sat in the queue.
 */
class ApproveNameAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'approveName';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Approve name');
        $this->color('success');
        $this->requiresConfirmation();

        $this->action(function (User $record) {
            $name = $record->pending_name;

            if ($record->approvePendingName()) {
                return;
            }

            Notification::make()
                ->warning()
                ->title('Name not approved')
                ->body("\"{$name}\" has been taken by someone else since it was requested, so the request was dropped. They can ask for another name.")
                ->persistent()
                ->send();
        });
    }
}
