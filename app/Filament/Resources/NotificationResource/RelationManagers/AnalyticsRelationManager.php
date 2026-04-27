<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class AnalyticsRelationManager extends RelationManager
{
    protected static string $relationship = 'analytics';

    protected static ?string $title = 'Диагностика доставки';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('channel')
                    ->label('Канал')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('retry_count')
                    ->label('Повторов')
                    ->sortable(),
                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Отправлено')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('delivered_at')
                    ->label('Доставлено')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('failed_at')
                    ->label('Ошибка')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('error_message')
                    ->label('Описание')
                    ->limit(80)
                    ->wrap(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('channel')
                    ->label('Канал')
                    ->options([
                        'in_app' => 'В приложении',
                        'email' => 'Email',
                        'telegram' => 'Telegram',
                        'websocket' => 'WebSocket',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'pending' => 'Ожидает',
                        'queued' => 'В очереди',
                        'sending' => 'Отправляется',
                        'sent' => 'Отправлено',
                        'delivered' => 'Доставлено',
                        'failed' => 'Ошибка',
                        'opened' => 'Открыто',
                        'clicked' => 'Переход',
                        'bounced' => 'Отклонено',
                        'complained' => 'Жалоба',
                    ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
