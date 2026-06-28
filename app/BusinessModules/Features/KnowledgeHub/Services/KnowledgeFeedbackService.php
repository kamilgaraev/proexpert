<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Services;

use App\BusinessModules\Features\KnowledgeHub\DTOs\KnowledgeAccessContext;
use App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeArticleFeedback;

class KnowledgeFeedbackService
{
    /**
     * @param array<string, mixed> $payload
     */
    public function store(KnowledgeAccessContext $context, array $payload): KnowledgeArticleFeedback
    {
        return KnowledgeArticleFeedback::query()->create([
            'article_id' => (int) $payload['article_id'],
            'user_id' => $context->userId,
            'organization_id' => $context->organizationId,
            'surface' => $context->surface->value,
            'context_key' => $context->contextKey,
            'reaction' => (string) $payload['reaction'],
            'comment' => $payload['comment'] ?? null,
        ]);
    }
}
