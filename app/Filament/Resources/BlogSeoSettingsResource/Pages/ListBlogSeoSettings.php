<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogSeoSettingsResource\Pages;

use App\Filament\Resources\BlogSeoSettingsResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBlogSeoSettings extends ListRecords
{
    protected static string $resource = BlogSeoSettingsResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
