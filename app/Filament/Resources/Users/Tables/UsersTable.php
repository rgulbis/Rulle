<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\Actions\ApproveNameAction;
use App\Filament\Resources\Users\Actions\CloseAccountAction;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('pending_name')
                    ->label('Requested name')
                    ->placeholder('—')
                    ->color('warning')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'danger',
                        'employee' => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->getStateUsing(fn ($record) => $record->email_verified_at !== null),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options([
                        'admin' => 'Admin',
                        'employee' => 'Employee',
                        'user' => 'User',
                    ]),
                TernaryFilter::make('pending_name')
                    ->label('Name change requested')
                    ->trueLabel('Pending review')
                    ->falseLabel('None')
                    ->nullable(),
            ])
            ->recordActions([
                ApproveNameAction::make()
                    ->visible(fn (User $record) => $record->pending_name !== null),
                Action::make('rejectName')
                    ->label('Reject name')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (User $record) => $record->pending_name !== null)
                    ->modalDescription(fn (User $record): string => "Turn down \"{$record->pending_name}\"? They keep their current name and can ask for another.")
                    ->action(function (User $record) {
                        $record->rejectPendingName();

                        Notification::make()->success()->title('Name request rejected')->send();
                    }),
                EditAction::make(),
                // No bulk close: every account needs its own Stripe call and
                // its own guards (last admin, upcoming reservations), and a
                // mistake here can't be undone.
                CloseAccountAction::make(),
            ]);
    }
}
