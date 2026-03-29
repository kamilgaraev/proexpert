<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogCommentResource\Pages;

use App\Filament\Resources\BlogCommentResource;
use Filament\Resources\Pages\ListRecords;

class ListBlogComments extends ListRecords
{
    protected static string $resource = BlogCommentResource::class;
}
