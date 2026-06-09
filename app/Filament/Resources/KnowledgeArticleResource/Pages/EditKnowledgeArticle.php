<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleResource\Pages;

use App\Filament\Resources\KnowledgeArticleResource;
use App\Models\SystemAdmin;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditKnowledgeArticle extends EditRecord
{
    protected static string $resource = KnowledgeArticleResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $systemAdmin = Auth::guard('system_admin')->user();

        if ($systemAdmin instanceof SystemAdmin) {
            $data['updated_by_system_admin_id'] = $systemAdmin->id;
        }

        return $data;
    }
}
