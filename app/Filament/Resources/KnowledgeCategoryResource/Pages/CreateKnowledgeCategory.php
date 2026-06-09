<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeCategoryResource\Pages;

use App\Filament\Resources\KnowledgeCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKnowledgeCategory extends CreateRecord
{
    protected static string $resource = KnowledgeCategoryResource::class;
}
