<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleResource\Pages;

use App\Filament\Resources\KnowledgeArticleResource;
use App\Models\SystemAdmin;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateKnowledgeArticle extends CreateRecord
{
    protected static string $resource = KnowledgeArticleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $systemAdmin = Auth::guard('system_admin')->user();

        if ($systemAdmin instanceof SystemAdmin) {
            $data['created_by_system_admin_id'] = $systemAdmin->id;
            $data['updated_by_system_admin_id'] = $systemAdmin->id;
        }

        return $data;
    }
}
