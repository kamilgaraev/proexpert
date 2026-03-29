<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogCategoryResource\Pages;

use App\Enums\Blog\BlogContextEnum;
use App\Filament\Resources\BlogCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBlogCategory extends CreateRecord
{
    protected static string $resource = BlogCategoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['blog_context'] = BlogContextEnum::MARKETING->value;

        return $data;
    }
}
