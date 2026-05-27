<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\BlogCategoryResource\Pages;
use App\Filament\Support\Concerns\AuthorizesSystemAdminResource;
use App\Filament\Support\Concerns\HasDestructiveActionGuardrails;
use App\Filament\Support\NavigationGroups;
use App\Models\Blog\BlogCategory;
use App\Policies\SystemAdmin\BlogCategoryPolicy;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class BlogCategoryResource extends Resource
{
    use AuthorizesSystemAdminResource;
    use HasDestructiveActionGuardrails;

    protected static ?string $model = BlogCategory::class;

    protected static string $systemAdminPolicy = BlogCategoryPolicy::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-folder';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return NavigationGroups::blog();
    }

    public static function getNavigationLabel(): string
    {
        return 'Категории блога';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Категория')
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Название')->required(),
                    Forms\Components\TextInput::make('slug')->label('Slug')->required(),
                    Forms\Components\Textarea::make('description')->label('Описание')->rows(3),
                    Forms\Components\TextInput::make('color')->label('Цвет')->default('#f59e0b')->required(),
                    Forms\Components\TextInput::make('image')->label('Изображение'),
                    Forms\Components\TextInput::make('sort_order')->label('Сортировка')->numeric()->default(0),
                    Forms\Components\Toggle::make('is_active')->label('Активна')->default(true),
                    Forms\Components\TextInput::make('meta_title')->label(trans_message('blog_cms.field_seo_title')),
                    Forms\Components\Textarea::make('meta_description')->label(trans_message('blog_cms.field_seo_description'))->rows(3),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->marketing())
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Название')->searchable()->sortable(),
                Tables\Columns\ColorColumn::make('color')->label('Цвет'),
                Tables\Columns\IconColumn::make('is_active')->label('Активна')->boolean(),
                Tables\Columns\TextColumn::make('sort_order')->label('Порядок')->sortable(),
            ])
            ->actions([
                EditAction::make(),
                self::guardedDeleteAction('category'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlogCategories::route('/'),
            'create' => Pages\CreateBlogCategory::route('/create'),
            'edit' => Pages\EditBlogCategory::route('/{record}/edit'),
        ];
    }
}
