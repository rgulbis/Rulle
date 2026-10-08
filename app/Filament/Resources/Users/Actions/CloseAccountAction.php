<?php

namespace App\Filament\Resources\Users\Actions;

use App\Models\User;
use App\Support\Accounts\AccountClosure;
use App\Support\Accounts\AccountClosureBlocked;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Stripe\Exception\ApiErrorException;

/**
 * "Delete" for a user, which closes the account instead (see
 * AccountClosure): same button and the same place, but the confirmation says
 * what will happen, and a guard that says no explains itself rather than
 * failing.
 */
class CloseAccountAction extends DeleteAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Close account'));

        $this->modalHeading(fn (User $record): string => __('Close :name\'s account?', ['name' => $record->name]));

        $this->modalDescription(__('Their subscription is cancelled at Stripe, and their name, email and password are replaced so they can no longer sign in or be identified. Their purchases, reservations, check-ins and payments are kept. This can\'t be undone.'));

        $this->modalSubmitActionLabel(__('Close account'));

        $this->successNotificationTitle(__('Account closed'));

        $this->failureNotificationTitle(__('The account was not closed'));

        $this->before(function (CloseAccountAction $action, User $record) {
            $reasons = app(AccountClosure::class)->blockers($record, auth()->user());

            if ($reasons === []) {
                return;
            }

            Notification::make()
                ->danger()
                ->title(__(':name\'s account can\'t be closed', ['name' => $record->name]))
                ->body(implode(' ', $reasons))
                ->persistent()
                ->send();

            $action->cancel();
        });

        $this->using(function (CloseAccountAction $action, User $record): bool {
            try {
                app(AccountClosure::class)->close($record, auth()->user());
            } catch (AccountClosureBlocked $e) {
                // Became blocked between the confirmation and now.
                $action->failureNotificationTitle($e->getMessage());

                return false;
            } catch (ApiErrorException $e) {
                report($e);
                $action->failureNotificationTitle(__('Stripe could not cancel their subscription, so nothing was changed. Try again in a moment.'));

                return false;
            }

            return true;
        });
    }
}
