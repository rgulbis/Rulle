<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Users\Actions\ApproveNameAction;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class PendingNameChanges extends TableWidget
{
    protected static ?int $sort = 4;

    protected static ?string $heading = 'Name changes awaiting review';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => User::query()->whereNotNull('pending_name'))
            ->emptyStateHeading('Nothing pending')
            ->emptyStateDescription('No name changes are waiting for review right now.')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->columns([
                TextColumn::make('name')
                    ->label('Current name'),
                TextColumn::make('pending_name')
                    ->label('Requested name')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->color('warning')
                    ->weight('bold'),
                TextColumn::make('email')
                    ->label('Email address')
                    ->color('gray'),
            ])
            ->recordActions([
                ApproveNameAction::make()
                    ->label('Approve')
                    ->icon(Heroicon::OutlinedCheck),
                Action::make('rejectName')
                    ->label('Reject')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (User $record) => $record->rejectPendingName()),
            ]);
    }
}
