<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\BusinessModules\Features\Notifications\Models\NotificationAnalytics;
use App\Filament\Resources\NotificationAnalyticsResource\Pages;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NotificationAnalyticsResource extends Resource
{
    protected static ?string $model = NotificationAnalytics::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static string | \UnitEnum | null $navigationGroup = 'Уведомления';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return 'Диагностика';
    }

    public static function getModelLabel(): string
    {
        return 'запись диагностики';
    }

    public static function getPluralModelLabel(): string
    {
        return 'диагностика уведомлений';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Доставка')
                    ->schema([
                        Infolists\Components\TextEntry::make('notification_id')
                            ->label('ID уведомления')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('notification.notification_type')
                            ->label('Тип события')
                            ->badge(),
                        Infolists\Components\TextEntry::make('channel')
                            ->label('Канал')
                            ->badge(),
                        Infolists\Components\TextEntry::make('status')
                            ->label('Статус')
                            ->badge(),
                        Infolists\Components\TextEntry::make('retry_count')
                            ->label('Повторов'),
                        Infolists\Components\TextEntry::make('tracking_id')
                            ->label('Трекинг')
                            ->copyable()
                            ->placeholder('Не указан'),
                    ])
                    ->columns(2),
                Section::make('Временная шкала')
                    ->schema([
                        Infolists\Components\TextEntry::make('sent_at')
                            ->label('Отправлено')
                            ->dateTime()
                            ->placeholder('Нет данных'),
                        Infolists\Components\TextEntry::make('delivered_at')
                            ->label('Доставлено')
                            ->dateTime()
                            ->placeholder('Нет данных'),
                        Infolists\Components\TextEntry::make('opened_at')
                            ->label('Открыто')
                            ->dateTime()
                            ->placeholder('Нет данных'),
                        Infolists\Components\TextEntry::make('clicked_at')
                            ->label('Переход')
                            ->dateTime()
                            ->placeholder('Нет данных'),
                        Infolists\Components\TextEntry::make('failed_at')
                            ->label('Ошибка')
                            ->dateTime()
                            ->placeholder('Нет данных'),
                    ])
                    ->columns(2),
                Section::make('Подробности')
                    ->schema([
                        Infolists\Components\TextEntry::make('error_message')
                            ->label('Описание')
                            ->placeholder('Нет данных')
                            ->columnSpanFull(),
                        Infolists\Components\KeyValueEntry::make('metadata')
                            ->label('Дополнительно')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('notification'))
            ->columns([
                Tables\Columns\TextColumn::make('notification.notification_type')
                    ->label('Тип события')
                    ->searchable()
                    ->sortable()
                    ->badge(),
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
                    ->limit(70)
                    ->wrap()
                    ->toggleable(),
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
                Tables\Filters\Filter::make('sent_at')
                    ->label('Период отправки')
                    ->schema([
                        Forms\Components\DatePicker::make('sent_from')
                            ->label('С даты'),
                        Forms\Components\DatePicker::make('sent_until')
                            ->label('По дату'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['sent_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('sent_at', '>=', $date))
                        ->when($data['sent_until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('sent_at', '<=', $date))),
            ])
            ->actions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationAnalytics::route('/'),
            'view' => Pages\ViewNotificationAnalytics::route('/{record}'),
        ];
    }
}
