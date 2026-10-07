<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Actions\CloseAccountAction;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CloseAccountAction::make(),
        ];
    }

    // See CreateUser::handleRecordCreation — same reasoning: `role` is
    // deliberately not mass-assignable, so this admin-only path sets it via
    // forceFill instead of the default $record->update($data).
    //
    // The role guard the form already ran is decided again inside the
    // transaction: two admins demoting each other at once both pass the
    // form's check, and only one of them may go through.
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        DB::transaction(function () use ($record, $data) {
            $current = User::query()->whereKey($record->getKey())->firstOrFail();

            $reason = $current->roleChangeBlocker($data['role'] ?? $current->role, auth()->user());

            if ($reason !== null) {
                Notification::make()->danger()->title($reason)->persistent()->send();

                throw new Halt;
            }

            $record->forceFill($data)->save();
        });

        return $record;
    }
}
