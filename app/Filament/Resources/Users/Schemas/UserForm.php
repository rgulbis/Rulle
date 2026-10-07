<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Unique across closed accounts too (unique() reads the table,
                // not the model's soft-delete scope), matching the database.
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->rules([
                        fn (?User $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                            $requestedBySomeoneElse = User::where('pending_name', $value)
                                ->when($record, fn ($query) => $query->where('id', '!=', $record->id))
                                ->exists();

                            if ($requestedBySomeoneElse) {
                                $fail('Another user has asked for this name.');
                            }
                        },
                    ]),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
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
                TextInput::make('password')
                    ->password()
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->helperText('Leave blank to keep the current password.'),
            ]);
    }
}
