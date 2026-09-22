<?php

namespace App\Filament\Resources\ChatMessages\Tables;

use App\Models\ChatMessage;
use App\Support\ChatModeration;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChatMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('From')
                    ->searchable(),
                TextColumn::make('user.role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin', 'employee' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('body')
                    ->label('Message')
                    ->limit(80)
                    ->wrap(),
                IconColumn::make('pinned_at')
                    ->label('Pinned')
                    ->boolean()
                    ->getStateUsing(fn (ChatMessage $record) => $record->pinned_at !== null),
                IconColumn::make('user.chat_muted_until')
                    ->label('Muted')
                    ->boolean()
                    ->getStateUsing(fn (ChatMessage $record) => $record->user->isChatMuted()),
                TextColumn::make('created_at')
                    ->label('Sent')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('pin')
                    ->color('info')
                    ->visible(fn (ChatMessage $record) => $record->pinned_at === null)
                    ->action(fn (ChatMessage $record) => $record->pin()),
                Action::make('unpin')
                    ->color('gray')
                    ->visible(fn (ChatMessage $record) => $record->pinned_at !== null)
                    ->action(fn (ChatMessage $record) => $record->unpin()),
                Action::make('mute')
                    ->color('warning')
                    ->visible(fn (ChatMessage $record) => ChatModeration::canMute(auth()->user(), $record->user))
                    ->schema([
                        Select::make('hours')
                            ->label('Mute for')
                            ->options([
                                1 => '1 hour',
                                24 => '1 day',
                                168 => '1 week',
                                720 => '30 days',
                            ])
                            ->default(24)
                            ->required(),
                    ])
                    ->action(function (ChatMessage $record, array $data) {
                        // Re-checked here: hiding a button isn't authorization.
                        abort_unless(ChatModeration::canMute(auth()->user(), $record->user), 403);

                        $record->user->update([
                            'chat_muted_until' => now()->addHours((int) $data['hours']),
                        ]);
                    }),
                Action::make('unmute')
                    ->color('gray')
                    ->visible(fn (ChatMessage $record) => $record->user->isChatMuted()
                        && ChatModeration::canMute(auth()->user(), $record->user))
                    ->requiresConfirmation()
                    ->action(function (ChatMessage $record) {
                        abort_unless(ChatModeration::canMute(auth()->user(), $record->user), 403);

                        $record->user->update(['chat_muted_until' => null]);
                    }),
                DeleteAction::make()
                    ->authorize(fn (ChatMessage $record) => ChatModeration::canDelete(auth()->user(), $record)),
            ]);
    }
}
