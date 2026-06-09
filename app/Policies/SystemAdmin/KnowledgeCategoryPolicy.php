<?php

declare(strict_types=1);

namespace App\Policies\SystemAdmin;

use App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeCategory;
use App\Filament\Support\FilamentPermission;
use App\Models\SystemAdmin;

class KnowledgeCategoryPolicy extends BaseSystemAdminPolicy
{
    public function viewAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, FilamentPermission::KNOWLEDGE_HUB_CATEGORIES_MANAGE);
    }

    public function view(SystemAdmin $systemAdmin, KnowledgeCategory $category): bool
    {
        return $this->allows($systemAdmin, FilamentPermission::KNOWLEDGE_HUB_CATEGORIES_MANAGE);
    }

    public function create(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, FilamentPermission::KNOWLEDGE_HUB_CATEGORIES_MANAGE);
    }

    public function update(SystemAdmin $systemAdmin, KnowledgeCategory $category): bool
    {
        return $this->allows($systemAdmin, FilamentPermission::KNOWLEDGE_HUB_CATEGORIES_MANAGE);
    }

    public function delete(SystemAdmin $systemAdmin, KnowledgeCategory $category): bool
    {
        return $this->allows($systemAdmin, FilamentPermission::KNOWLEDGE_HUB_CATEGORIES_MANAGE);
    }

    public function deleteAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, FilamentPermission::KNOWLEDGE_HUB_CATEGORIES_MANAGE);
    }
}
