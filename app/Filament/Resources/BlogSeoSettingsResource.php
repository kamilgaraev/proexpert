<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Blog\BlogContextEnum;
use App\Filament\Resources\BlogSeoSettingsResource\Pages;
use App\Models\Blog\BlogSeoSettings;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class BlogSeoSettingsResource extends Resource
{
    protected static ?string $model = BlogSeoSettings::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-globe-alt';

    protected static string | \UnitEnum | null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return 'SEO блога';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('SEO')
                ->schema([
                    Forms\Components\TextInput::make('site_name')->label('Название сайта')->required(),
                    Forms\Components\Textarea::make('site_description')->label('Описание')->rows(3),
                    Forms\Components\TagsInput::make('site_keywords')->label('Ключевые слова'),
                    Forms\Components\TextInput::make('default_og_image')->label('OG image'),
                    Forms\Components\Toggle::make('auto_generate_meta_description')->label('Автогенерация meta description'),
                    Forms\Components\TextInput::make('meta_description_length')->label('Длина meta description')->numeric(),
                    Forms\Components\Toggle::make('enable_breadcrumbs')->label('Breadcrumbs'),
                    Forms\Components\Toggle::make('enable_structured_data')->label('Structured data'),
                    Forms\Components\Toggle::make('enable_sitemap')->label('Sitemap'),
                    Forms\Components\Toggle::make('enable_rss')->label('RSS'),
                    Forms\Components\Textarea::make('robots_txt')->label('robots.txt')->rows(6),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->where('blog_context', BlogContextEnum::MARKETING->value))
            ->columns([
                Tables\Columns\TextColumn::make('site_name')->label('Сайт'),
                Tables\Columns\IconColumn::make('enable_sitemap')->label('Sitemap')->boolean(),
                Tables\Columns\IconColumn::make('enable_rss')->label('RSS')->boolean(),
            ])
            ->actions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlogSeoSettings::route('/'),
            'create' => Pages\CreateBlogSeoSettings::route('/create'),
            'edit' => Pages\EditBlogSeoSettings::route('/{record}/edit'),
        ];
    }
}
