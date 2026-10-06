<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

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
                Action::make('approveName')
                    ->label('Approve name')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (User $record) => $record->pending_name !== null)
                    ->action(function (User $record) {
                        try {
                            $record->approvePendingName();
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('rejectName')
                    ->label('Reject name')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (User $record) => $record->pending_name !== null)
                    ->action(fn (User $record) => $record->rejectPendingName()),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('closeAccounts')
                        ->label('Close accounts')
                        ->color('danger')
                        ->icon('heroicon-o-lock-closed')
                        ->requiresConfirmation()
                        ->modalDescription('Ends any Stripe subscription, removes personal data and blocks the account. Payments, reservations and check-ins are kept.')
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => self::closeAccounts($records)),
                ]),
            ]);
    }

    /**
     * Closes each account that can be closed and says, per account, why any
     * couldn't — one blocked or failing user doesn't stop the rest.
     *
     * @param  Collection<int, User>  $users
     * @return bool whether every account was closed
     */
    public static function closeAccounts(Collection $users): bool
    {
        $closed = 0;
        $failures = [];

        foreach ($users as $user) {
            try {
                $user->closeAccount(Auth::user());
                $closed++;
            } catch (DomainException $e) {
                $failures[] = "{$user->name}: {$e->getMessage()}";
            } catch (\Throwable $e) {
                report($e);
                $failures[] = "{$user->name}: could not end their Stripe subscription — nothing was changed, try again.";
            }
        }

        if ($closed > 0) {
            Notification::make()->title("Closed {$closed} account(s)")->success()->send();
        }

        if ($failures) {
            Notification::make()->title('Not closed')->body(implode("\n", $failures))->danger()->persistent()->send();
        }

        return $failures === [];
    }
}
