<?php

namespace App\Filament\Resources\ChatMessages\Tables;

use App\Models\ChatMessage;
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
            // Only the global room: reservation group chats are private to
            // their group, so they're neither listed nor moderated here.
            ->modifyQueryUsing(fn ($query) => $query->whereNull('reservation_id'))
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
                    ->action(fn (ChatMessage $record, array $data) => $record->user->update([
                        'chat_muted_until' => now()->addHours((int) $data['hours']),
                    ])),
                Action::make('unmute')
                    ->color('gray')
                    ->visible(fn (ChatMessage $record) => $record->user->isChatMuted())
                    ->requiresConfirmation()
                    ->action(fn (ChatMessage $record) => $record->user->update(['chat_muted_until' => null])),
                DeleteAction::make(),
            ]);
    }
}
