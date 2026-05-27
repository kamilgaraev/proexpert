<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogArticleResource\Pages;

use App\Filament\Resources\BlogArticleResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class BlogEditorialCalendar extends ListRecords
{
    protected static string $resource = BlogArticleResource::class;

    public function getTitle(): string
    {
        return trans_message('blog_cms.editorial_calendar_title');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('all_articles')
                ->label(trans_message('blog_cms.article_list_action'))
                ->icon('heroicon-o-queue-list')
                ->url(BlogArticleResource::getUrl('index')),
            CreateAction::make(),
        ];
    }
}
