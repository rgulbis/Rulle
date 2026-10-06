<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Tables\UsersTable;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('closeAccount')
                ->label('Close account')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Ends any Stripe subscription, removes the person\'s personal data and blocks the account. Their payments, reservations and check-ins are kept.')
                ->action(function (User $record) {
                    if (UsersTable::closeAccounts(collect([$record]))) {
                        $this->redirect(UserResource::getUrl('index'));
                    }
                }),
        ];
    }

    /**
     * An admin can't lock everyone out of the panel: the last administrator
     * (or the account you're logged in as) can't be demoted.
     */
    protected function beforeSave(): void
    {
        /** @var User $record */
        $record = $this->record;

        if (! $record->isAdmin() || ($this->data['role'] ?? 'admin') === 'admin') {
            return;
        }

        $reason = $record->is(auth()->user())
            ? 'You cannot remove your own admin role.'
            : (User::where('role', 'admin')->count() <= 1 ? 'This is the last administrator.' : null);

        if ($reason) {
            Notification::make()->title($reason)->danger()->send();
            $this->halt();
        }
    }

    // See CreateUser::handleRecordCreation — same reasoning: `role` is
    // deliberately not mass-assignable, so this admin-only path sets it via
    // forceFill instead of the default $record->update($data).
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->forceFill($data)->save();

        return $record;
    }
}
