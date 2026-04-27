<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\BusinessModules\Features\Notifications\Models\NotificationTemplate;
use App\Filament\Resources\NotificationTemplateResource\Pages;
use App\Models\Organization;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-envelope-open';

    protected static string | \UnitEnum | null $navigationGroup = 'Уведомления';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return 'Шаблоны';
    }

    public static function getModelLabel(): string
    {
        return 'шаблон уведомления';
    }

    public static function getPluralModelLabel(): string
    {
        return 'шаблоны уведомлений';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основное')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('type')
                            ->label('Тип события')
                            ->required()
                            ->maxLength(100),
                        Forms\Components\Select::make('channel')
                            ->label('Канал')
                            ->options([
                                'in_app' => 'В приложении',
                                'email' => 'Email',
                                'telegram' => 'Telegram',
                                'websocket' => 'WebSocket',
                            ])
                            ->searchable()
                            ->required(),
                        Forms\Components\Select::make('organization_id')
                            ->label('Организация')
                            ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Forms\Components\TextInput::make('locale')
                            ->label('Язык')
                            ->required()
                            ->maxLength(10)
                            ->default('ru'),
                        Forms\Components\TextInput::make('version')
                            ->label('Версия')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                        Forms\Components\Toggle::make('is_default')
                            ->label('Шаблон по умолчанию')
                            ->default(false),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Активен')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('Содержимое')
                    ->schema([
                        Forms\Components\TextInput::make('subject')
                            ->label('Тема')
                            ->maxLength(500)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('content')
                            ->label('Текст')
                            ->required()
                            ->rows(10)
                            ->columnSpanFull(),
                        Forms\Components\KeyValue::make('variables')
                            ->label('Переменные')
                            ->keyLabel('Ключ')
                            ->valueLabel('Описание')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('organization'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Шаблон')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Тип события')
                    ->searchable()
                    ->sortable()
                    ->badge(),
                Tables\Columns\TextColumn::make('channel')
                    ->label('Канал')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('organization.name')
                    ->label('Организация')
                    ->placeholder('Общий шаблон')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('locale')
                    ->label('Язык')
                    ->sortable(),
                Tables\Columns\TextColumn::make('version')
                    ->label('Версия')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_default')
                    ->label('По умолчанию')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Обновлен')
                    ->dateTime()
                    ->sortable()
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
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Активность'),
                Tables\Filters\TernaryFilter::make('is_default')
                    ->label('По умолчанию'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationTemplates::route('/'),
            'create' => Pages\CreateNotificationTemplate::route('/create'),
            'edit' => Pages\EditNotificationTemplate::route('/{record}/edit'),
        ];
    }
}
