<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Services;

use App\BusinessModules\Features\KnowledgeHub\DTOs\KnowledgeAccessContext;
use App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeSearchEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

class KnowledgeSearchAnalyticsService
{
    public function recordSearch(KnowledgeAccessContext $context, string $query, int $resultsCount, ?int $clickedArticleId = null): void
    {
        try {
            KnowledgeSearchEvent::query()->create([
                'user_id' => $context->userId,
                'organization_id' => $context->organizationId,
                'clicked_article_id' => $clickedArticleId,
                'surface' => $context->surface->value,
                'query' => trim($query),
                'module_slug' => $context->moduleSlug,
                'context_key' => $context->contextKey,
                'results_count' => max($resultsCount, 0),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Knowledge hub search analytics was not recorded.', [
                'surface' => $context->surface->value,
                'user_id' => $context->userId,
                'organization_id' => $context->organizationId,
                'exception' => $exception,
            ]);
        }
    }
}
