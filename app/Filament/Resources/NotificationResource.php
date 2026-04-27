<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\Filament\Resources\NotificationResource\Pages;
use App\Filament\Resources\NotificationResource\RelationManagers\AnalyticsRelationManager;
use App\Filament\Widgets\NotificationDeliveryStatsWidget;
use App\Models\Organization;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NotificationResource extends Resource
{
    protected static ?string $model = Notification::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-bell-alert';

    protected static string | \UnitEnum | null $navigationGroup = 'Уведомления';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return 'Журнал доставок';
    }

    public static function getModelLabel(): string
    {
        return 'уведомление';
    }

    public static function getPluralModelLabel(): string
    {
        return 'журнал уведомлений';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Уведомление')
                    ->schema([
                        Infolists\Components\TextEntry::make('id')
                            ->label('ID')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('notification_type')
                            ->label('Тип события')
                            ->badge(),
                        Infolists\Components\TextEntry::make('priority')
                            ->label('Приоритет')
                            ->badge(),
                        Infolists\Components\TextEntry::make('organization.name')
                            ->label('Организация')
                            ->placeholder('Не указана'),
                        Infolists\Components\TextEntry::make('notifiable_type')
                            ->label('Получатель')
                            ->formatStateUsing(fn (?string $state, Notification $record): string => trim((string) $state . ' #' . (string) $record->notifiable_id)),
                        Infolists\Components\TextEntry::make('channels')
                            ->label('Каналы')
                            ->formatStateUsing(fn ($state): string => self::formatList($state))
                            ->badge(),
                        Infolists\Components\TextEntry::make('read_at')
                            ->label('Прочитано')
                            ->dateTime()
                            ->placeholder('Не прочитано'),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Создано')
                            ->dateTime(),
                    ])
                    ->columns(2),
                Section::make('Данные')
                    ->schema([
                        Infolists\Components\KeyValueEntry::make('delivery_status')
                            ->label('Статусы доставки')
                            ->columnSpanFull(),
                        Infolists\Components\KeyValueEntry::make('data')
                            ->label('Содержимое')
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
            ->modifyQueryUsing(fn ($query) => $query->with(['organization', 'analytics']))
            ->columns([
                Tables\Columns\TextColumn::make('notification_type')
                    ->label('Тип события')
                    ->searchable()
                    ->sortable()
                    ->badge(),
                Tables\Columns\TextColumn::make('priority')
                    ->label('Приоритет')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('channels')
                    ->label('Каналы')
                    ->formatStateUsing(fn ($state): string => self::formatList($state))
                    ->badge(),
                Tables\Columns\TextColumn::make('analytics_count')
                    ->label('Попыток')
                    ->counts('analytics')
                    ->sortable(),
                Tables\Columns\TextColumn::make('organization.name')
                    ->label('Организация')
                    ->placeholder('Не указана')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('read_at')
                    ->label('Прочитано')
                    ->boolean()
                    ->getStateUsing(fn (Notification $record): bool => $record->read_at !== null),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('priority')
                    ->label('Приоритет')
                    ->options([
                        'critical' => 'Критический',
                        'high' => 'Высокий',
                        'normal' => 'Обычный',
                        'low' => 'Низкий',
                    ]),
                Tables\Filters\SelectFilter::make('notification_type')
                    ->label('Тип события')
                    ->options(fn (): array => Notification::query()
                        ->whereNotNull('notification_type')
                        ->distinct()
                        ->orderBy('notification_type')
                        ->pluck('notification_type', 'notification_type')
                        ->all())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('organization_id')
                    ->label('Организация')
                    ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Tables\Filters\TernaryFilter::make('read_at')
                    ->label('Прочтение')
                    ->nullable()
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('read_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('read_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Tables\Filters\Filter::make('created_at')
                    ->label('Период')
                    ->schema([
                        Forms\Components\DatePicker::make('created_from')
                            ->label('С даты'),
                        Forms\Components\DatePicker::make('created_until')
                            ->label('По дату'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['created_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['created_until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ])
            ->actions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            AnalyticsRelationManager::class,
        ];
    }

    public static function getWidgets(): array
    {
        return [
            NotificationDeliveryStatsWidget::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotifications::route('/'),
            'view' => Pages\ViewNotification::route('/{record}'),
        ];
    }

    private static function formatList(mixed $state): string
    {
        if (is_array($state)) {
            return implode(', ', array_filter(array_map(static fn ($item): string => (string) $item, $state)));
        }

        return (string) ($state ?? '');
    }
}
