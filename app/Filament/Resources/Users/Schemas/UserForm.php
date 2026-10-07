<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use App\Rules\NoInappropriateContent;
use Closure;
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
                // Unique across closed accounts too (unique() reads the table,
                // not the model's soft-delete scope), matching the database.
                // Same content rules as the public profile form, so an admin
                // can't set a name a customer would be refused.
                TextInput::make('name')
                    ->required()
                    ->trim()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->rules([
                        new NoInappropriateContent,
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
                    ->trim()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    // Stored in its ASCII (punycode) form, as at registration,
                    // so it matches what login looks up; checked for
                    // uniqueness in that form too.
                    ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : User::normalizeEmailForLookup($state))
                    ->rules([
                        fn (?User $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                            $taken = User::withTrashed()
                                ->where('email', User::normalizeEmailForLookup((string) $value))
                                ->when($record, fn ($query) => $query->where('id', '!=', $record->id))
                                ->exists();

                            if ($taken) {
                                $fail(trans('validation.unique', ['attribute' => 'email address']));
                            }
                        },
                    ]),
                Select::make('role')
                    ->options([
                        'admin' => 'Admin',
                        'employee' => 'Employee',
                        'user' => 'User',
                    ])
                    ->required()
                    ->default('user')
                    ->rules([
                        fn (?User $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                            $reason = $record?->roleChangeBlocker((string) $value, auth()->user());

                            if ($reason !== null) {
                                $fail($reason);
                            }
                        },
                    ]),
                DateTimePicker::make('email_verified_at')
                    ->label('Email verified at'),
                TextInput::make('password')
                    ->password()
                    ->rule(Password::default())
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->helperText('Leave blank to keep the current password.'),
            ]);
    }
}
