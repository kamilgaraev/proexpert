<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogSeoSettingsResource\Pages;

use App\Enums\Blog\BlogContextEnum;
use App\Filament\Resources\BlogSeoSettingsResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBlogSeoSettings extends CreateRecord
{
    protected static string $resource = BlogSeoSettingsResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['blog_context'] = BlogContextEnum::MARKETING->value;

        return $data;
    }
}
