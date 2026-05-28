<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogMediaAssetResource\Pages;

use App\Filament\Resources\BlogMediaAssetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBlogMediaAssets extends ListRecords
{
    protected static string $resource = BlogMediaAssetResource::class;

    public function getTitle(): string
    {
        return trans_message('blog_cms.media_list_title');
    }

    public function getBreadcrumb(): string
    {
        return trans_message('blog_cms.media_list_title');
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
