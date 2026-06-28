<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Services;

use App\BusinessModules\Features\KnowledgeHub\DTOs\KnowledgeAccessContext;
use App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeArticle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class KnowledgeArticleTreeService
{
    public function __construct(private readonly KnowledgeAccessFilter $accessFilter)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return Collection<int, KnowledgeArticle>
     */
    public function tree(KnowledgeAccessContext $context, array $filters = []): Collection
    {
        $query = KnowledgeArticle::query()
            ->published()
            ->knowledge()
            ->with('category');

        $this->accessFilter->apply($query, $context);
        $this->applyFilters($query, $filters);

        $articles = $query
            ->orderBy('depth')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        return $this->buildTree($articles);
    }

    /**
     * @param Collection<int, KnowledgeArticle> $articles
     * @return Collection<int, KnowledgeArticle>
     */
    private function buildTree(Collection $articles): Collection
    {
        $byParent = $articles->groupBy(fn (KnowledgeArticle $article): int => (int) ($article->parent_id ?? 0));

        $attachChildren = function (KnowledgeArticle $article) use (&$attachChildren, $byParent): KnowledgeArticle {
            $children = ($byParent->get((int) $article->id) ?? collect())
                ->map(fn (KnowledgeArticle $child): KnowledgeArticle => $attachChildren($child))
                ->values();

            $article->setRelation('children', $children);

            return $article;
        };

        return ($byParent->get(0) ?? collect())
            ->map(fn (KnowledgeArticle $article): KnowledgeArticle => $attachChildren($article))
            ->values();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $category = trim((string) ($filters['category'] ?? ''));
        if ($category !== '') {
            $query->whereHas('category', fn (Builder $builder): Builder => $builder->where('slug', $category));
        }

        $tag = trim((string) ($filters['tag'] ?? ''));
        if ($tag !== '') {
            $query->whereJsonContains('tags', $tag);
        }
    }
}
