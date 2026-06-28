<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Services;

use App\BusinessModules\Features\KnowledgeHub\DTOs\KnowledgeAccessContext;
use App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeArticle;

class KnowledgeContextualHelpService
{
    public function __construct(private readonly KnowledgeAccessFilter $accessFilter)
    {
    }

    /**
     * @return array{primary: KnowledgeArticle|null, suggested: \Illuminate\Support\Collection<int, KnowledgeArticle>, context: array<string, mixed>}
     */
    public function resolve(KnowledgeAccessContext $context, int $limit = 4): array
    {
        $limit = min(max($limit, 1), 8);

        $query = KnowledgeArticle::query()
            ->published()
            ->knowledge()
            ->with('category');

        $this->accessFilter->apply($query, $context);

        $articles = $query
            ->orderByDesc('is_pinned')
            ->orderBy('help_priority')
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->limit(max(24, $limit * 6))
            ->get()
            ->sortBy(fn (KnowledgeArticle $article): int => $this->relevanceScore($article, $context))
            ->take($limit)
            ->values();

        return [
            'primary' => $articles->first(),
            'suggested' => $articles->skip(1)->values(),
            'context' => [
                'surface' => $context->surface->value,
                'module_slug' => $context->moduleSlug,
                'permission_key' => $context->permissionKey,
                'context_key' => $context->contextKey,
            ],
        ];
    }

    private function relevanceScore(KnowledgeArticle $article, KnowledgeAccessContext $context): int
    {
        $score = 0;

        if ($context->contextKey !== null && in_array($context->contextKey, $article->context_keys ?? [], true)) {
            $score -= 1000;
        }

        if ($context->moduleSlug !== null && in_array($context->moduleSlug, $article->module_slugs ?? [], true)) {
            $score -= 100;
        }

        if ($context->permissionKey !== null && in_array($context->permissionKey, $article->permission_keys ?? [], true)) {
            $score -= 10;
        }

        return $score;
    }
}
