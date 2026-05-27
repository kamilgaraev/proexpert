<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogArticleResource\Pages;

use App\Filament\Resources\BlogArticleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;

class ViewBlogArticle extends ViewRecord
{
    protected static string $resource = BlogArticleResource::class;

    protected Width | string | null $maxContentWidth = 'fi-blog-article-editor-screen';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label(trans_message('blog_cms.article_edit_action')),
        ];
    }
}
