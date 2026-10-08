<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\Actions\ApproveNameAction;
use App\Filament\Resources\Users\Actions\CloseAccountAction;
use App\Filament\Support\Labels;
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
                    ->label(__('Name'))
                    ->searchable(),
                TextColumn::make('pending_name')
                    ->label(__('Requested name'))
                    ->placeholder('—')
                    ->color('warning')
                    ->searchable(),
                TextColumn::make('email')
                    ->label(__('Email address'))
                    ->searchable(),
                TextColumn::make('role')
                    ->label(__('Role'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Labels::roles()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'danger',
                        'employee' => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('email_verified_at')
                    ->label(__('Verified'))
                    ->boolean()
                    ->getStateUsing(fn ($record) => $record->email_verified_at !== null),
                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label(__('Role'))
                    ->options(Labels::roles()),
                TernaryFilter::make('pending_name')
                    ->label(__('Name change requested'))
                    ->trueLabel(__('Pending review'))
                    ->falseLabel(__('None'))
                    ->nullable(),
            ])
            ->recordActions([
                ApproveNameAction::make()
                    ->visible(fn (User $record) => $record->pending_name !== null),
                Action::make('rejectName')
                    ->label(__('Reject name'))
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (User $record) => $record->pending_name !== null)
                    ->modalDescription(fn (User $record): string => __('Turn down ":name"? They keep their current name and can ask for another.', ['name' => $record->pending_name]))
                    ->action(function (User $record) {
                        $record->rejectPendingName();

                        Notification::make()->success()->title(__('Name request rejected'))->send();
                    }),
                EditAction::make(),
                // No bulk close: every account needs its own Stripe call and
                // its own guards (last admin, upcoming reservations), and a
                // mistake here can't be undone.
                CloseAccountAction::make(),
            ]);
    }
}
