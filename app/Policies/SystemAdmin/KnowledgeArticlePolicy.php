<?php

declare(strict_types=1);

namespace App\Policies\SystemAdmin;

use App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeArticle;
use App\Filament\Support\FilamentPermission;
use App\Models\SystemAdmin;

class KnowledgeArticlePolicy extends BaseSystemAdminPolicy
{
    public function viewAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, FilamentPermission::KNOWLEDGE_HUB_ARTICLES_VIEW);
    }

    public function view(SystemAdmin $systemAdmin, KnowledgeArticle $article): bool
    {
        return $this->allows($systemAdmin, FilamentPermission::KNOWLEDGE_HUB_ARTICLES_VIEW);
    }

    public function create(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, FilamentPermission::KNOWLEDGE_HUB_ARTICLES_CREATE);
    }

    public function update(SystemAdmin $systemAdmin, KnowledgeArticle $article): bool
    {
        return $this->allows($systemAdmin, FilamentPermission::KNOWLEDGE_HUB_ARTICLES_UPDATE);
    }

    public function delete(SystemAdmin $systemAdmin, KnowledgeArticle $article): bool
    {
        return $this->allows($systemAdmin, FilamentPermission::KNOWLEDGE_HUB_ARTICLES_DELETE);
    }

    public function deleteAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, FilamentPermission::KNOWLEDGE_HUB_ARTICLES_DELETE);
    }
}
