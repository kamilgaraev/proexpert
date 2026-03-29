<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogMediaAssetResource\Pages;

use App\Filament\Resources\BlogMediaAssetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBlogMediaAssets extends ListRecords
{
    protected static string $resource = BlogMediaAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
