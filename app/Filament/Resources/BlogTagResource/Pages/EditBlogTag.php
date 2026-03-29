<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogTagResource\Pages;

use App\Filament\Resources\BlogTagResource;
use Filament\Resources\Pages\EditRecord;

class EditBlogTag extends EditRecord
{
    protected static string $resource = BlogTagResource::class;
}
