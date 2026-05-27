<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Blog\BlogContextEnum;
use App\Filament\Resources\BlogSeoSettingsResource\Pages;
use App\Filament\Support\Concerns\AuthorizesSystemAdminResource;
use App\Filament\Support\NavigationGroups;
use App\Models\Blog\BlogSeoSettings;
use App\Policies\SystemAdmin\BlogSeoSettingsPolicy;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class BlogSeoSettingsResource extends Resource
{
    use AuthorizesSystemAdminResource;

    protected static ?string $model = BlogSeoSettings::class;

    protected static string $systemAdminPolicy = BlogSeoSettingsPolicy::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?int $navigationSort = 60;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return NavigationGroups::blog();
    }

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
                    Forms\Components\TextInput::make('default_og_image')->label(trans_message('blog_cms.field_open_graph_image')),
                    Forms\Components\Toggle::make('auto_generate_meta_description')->label(trans_message('blog_cms.field_auto_seo_description')),
                    Forms\Components\TextInput::make('meta_description_length')->label(trans_message('blog_cms.field_seo_description_length'))->numeric(),
                    Forms\Components\Toggle::make('enable_breadcrumbs')->label(trans_message('blog_cms.field_breadcrumbs')),
                    Forms\Components\Toggle::make('enable_structured_data')->label(trans_message('blog_cms.field_structured_data')),
                    Forms\Components\Toggle::make('enable_sitemap')->label(trans_message('blog_cms.field_sitemap')),
                    Forms\Components\Toggle::make('enable_rss')->label('RSS'),
                    Forms\Components\Textarea::make('robots_txt')->label(trans_message('blog_cms.field_robots_txt'))->rows(6),
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
                Tables\Columns\IconColumn::make('enable_sitemap')->label(trans_message('blog_cms.field_sitemap'))->boolean(),
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
