<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogTagResource\Pages;

use App\Filament\Resources\BlogTagResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBlogTags extends ListRecords
{
    protected static string $resource = BlogTagResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
