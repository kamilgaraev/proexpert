<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\BlogTagResource\Pages;
use App\Models\Blog\BlogTag;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class BlogTagResource extends Resource
{
    protected static ?string $model = BlogTag::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-hashtag';

    protected static string | \UnitEnum | null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return 'Теги блога';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Тег')
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Название')->required(),
                    Forms\Components\TextInput::make('slug')->label('Slug')->required(),
                    Forms\Components\Textarea::make('description')->label('Описание')->rows(3),
                    Forms\Components\TextInput::make('color')->label('Цвет')->default('#334155')->required(),
                    Forms\Components\Toggle::make('is_active')->label('Активен')->default(true),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->marketing())
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Тег')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('usage_count')->label('Использований')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label('Активен')->boolean(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlogTags::route('/'),
            'create' => Pages\CreateBlogTag::route('/create'),
            'edit' => Pages\EditBlogTag::route('/{record}/edit'),
        ];
    }
}
