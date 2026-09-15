<?php

namespace App\Filament\Auth;

use App\Models\User;
use Filament\Auth\Pages\Login as BaseLogin;
use SensitiveParameter;

class Login extends BaseLogin
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [
            ...parent::getCredentialsFromFormData($data),
            'email' => User::normalizeEmailForLookup($data['email']),
        ];
    }
}
