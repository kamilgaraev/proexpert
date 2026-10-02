<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeArticle;
use Illuminate\Database\Eloquent\Builder;

final class KnowledgeHubRagSource extends ModelDomainRagSource
{
    public function sourceType(): string
    {
        return 'knowledge';
    }

    public function entities(): array
    {
        return ['knowledge_article' => ['model' => KnowledgeArticle::class, 'fields' => ['id','title','slug','excerpt','content_plain_text','tags','status','published_at','reading_time']]];
    }

    protected function query(string $class, int $organizationId, ?int $projectId): Builder
    {
        return KnowledgeArticle::query()->published();
    }
}
