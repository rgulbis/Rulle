<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class PendingNameChanges extends TableWidget
{
    protected static ?string $heading = 'Name changes awaiting review';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => User::query()->whereNotNull('pending_name'))
            ->emptyStateHeading('Nothing pending')
            ->emptyStateDescription('No name changes are waiting for review right now.')
            ->columns([
                TextColumn::make('name')
                    ->label('Current name'),
                TextColumn::make('pending_name')
                    ->label('Requested name')
                    ->color('warning')
                    ->weight('bold'),
                TextColumn::make('email')
                    ->label('Email address'),
            ])
            ->recordActions([
                Action::make('approveName')
                    ->label('Approve')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn (User $record) => $record->approvePendingName()),
                Action::make('rejectName')
                    ->label('Reject')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (User $record) => $record->rejectPendingName()),
            ]);
    }
}
