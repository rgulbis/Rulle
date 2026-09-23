<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * `role` isn't in User's mass-assignable list (it's a privilege
     * boundary, kept off-limits everywhere else in the app), so the default
     * `new User($data)` this method otherwise runs would silently drop it.
     * forceCreate() bypasses that deliberately, for this trusted,
     * admin-only path only.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return User::forceCreate($data);
    }
}
