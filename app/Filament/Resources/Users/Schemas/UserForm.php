<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use App\Rules\NoInappropriateContent;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // The same rules a customer's own display name has to pass: a
                // name is how people are identified in chat and reservation
                // groups, so it must be unique and appropriate no matter who
                // typed it.
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->rule(new NoInappropriateContent)
                    ->dehydrateStateUsing(fn (?string $state) => trim((string) $state)),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    // Stored the way login looks it up (Unicode domain ->
                    // punycode), so an address entered here can log in.
                    ->dehydrateStateUsing(fn (?string $state) => User::normalizeEmailForLookup(trim((string) $state))),
                Select::make('role')
                    ->options([
                        'admin' => 'Admin',
                        'employee' => 'Employee',
                        'user' => 'User',
                    ])
                    ->required()
                    ->default('user'),
                DateTimePicker::make('email_verified_at')
                    ->label('Email verified at'),
                // Same policy as public registration (Password::defaults()).
                TextInput::make('password')
                    ->password()
                    ->rule(Password::defaults())
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->helperText('Leave blank to keep the current password.'),
            ]);
    }
}
