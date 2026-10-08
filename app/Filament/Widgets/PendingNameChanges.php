<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Users\Actions\ApproveNameAction;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class PendingNameChanges extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Name changes awaiting review'))
            ->query(fn (): Builder => User::query()->whereNotNull('pending_name'))
            ->emptyStateHeading(__('Nothing pending'))
            ->emptyStateDescription(__('No name changes are waiting for review right now.'))
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->columns([
                TextColumn::make('name')
                    ->label(__('Current name')),
                TextColumn::make('pending_name')
                    ->label(__('Requested name'))
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->color('warning')
                    ->weight('bold'),
                TextColumn::make('email')
                    ->label(__('Email address'))
                    ->color('gray'),
            ])
            ->recordActions([
                ApproveNameAction::make()
                    ->label(__('Approve'))
                    ->icon(Heroicon::OutlinedCheck),
                Action::make('rejectName')
                    ->label(__('Reject'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (User $record): string => __('Turn down ":name"? They keep their current name and can ask for another.', ['name' => $record->pending_name]))
                    ->action(function (User $record) {
                        $record->rejectPendingName();

                        Notification::make()->success()->title(__('Name request rejected'))->send();
                    }),
            ]);
    }
}
