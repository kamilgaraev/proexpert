<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\BlogArticleResource\Pages;
use App\Filament\Resources\BlogArticleResource\RelationManagers\BlogArticleRevisionsRelationManager;
use App\Filament\Resources\BlogArticleResource\Schemas\BlogArticleForm;
use App\Filament\Resources\BlogArticleResource\Schemas\BlogArticleInfolist;
use App\Filament\Resources\BlogArticleResource\Schemas\BlogArticleTable;
use App\Filament\Support\Concerns\AuthorizesSystemAdminResource;
use App\Filament\Support\Concerns\HasDestructiveActionGuardrails;
use App\Models\Blog\BlogArticle;
use App\Policies\SystemAdmin\BlogArticlePolicy;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class BlogArticleResource extends Resource
{
    use AuthorizesSystemAdminResource;
    use HasDestructiveActionGuardrails;

    protected static ?string $model = BlogArticle::class;

    protected static string $systemAdminPolicy = BlogArticlePolicy::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected static string | \UnitEnum | null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return 'Блог';
    }

    public static function getModelLabel(): string
    {
        return 'статья';
    }

    public static function getPluralModelLabel(): string
    {
        return 'статьи';
    }

    public static function form(Schema $schema): Schema
    {
        return BlogArticleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BlogArticleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BlogArticleTable::configure($table);
    }

    public static function guardedArticleDeleteAction(): DeleteAction
    {
        return self::guardedDeleteAction('article');
    }

    public static function getRelations(): array
    {
        return [
            BlogArticleRevisionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlogArticles::route('/'),
            'create' => Pages\CreateBlogArticle::route('/create'),
            'edit' => Pages\EditBlogArticle::route('/{record}/edit'),
        ];
    }
}
