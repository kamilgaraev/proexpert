<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogSeoSettingsResource\Pages;

use App\Filament\Resources\BlogSeoSettingsResource;
use Filament\Resources\Pages\EditRecord;

class EditBlogSeoSettings extends EditRecord
{
    protected static string $resource = BlogSeoSettingsResource::class;
}
