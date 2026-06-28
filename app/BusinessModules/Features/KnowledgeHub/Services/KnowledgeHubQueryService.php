<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Services;

use App\BusinessModules\Features\KnowledgeHub\DTOs\KnowledgeAccessContext;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeArticleKind;
use App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeArticle;
use App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class KnowledgeHubQueryService
{
    private const DEFAULT_PER_PAGE = 12;
    private const MAX_PER_PAGE = 30;

    public function __construct(
        private readonly KnowledgeAccessFilter $accessFilter,
        private readonly KnowledgeFullTextSearchService $fullTextSearch,
    ) {
    }

    /**
     * @return array{
     *     categories: Collection<int, KnowledgeCategory>,
     *     featured_articles: Collection<int, KnowledgeArticle>,
     *     latest_changelog: Collection<int, KnowledgeArticle>,
     *     summary: array<string, int>
     * }
     */
    public function overview(?KnowledgeAccessContext $context = null): array
    {
        $categories = KnowledgeCategory::query()
            ->active()
            ->ordered()
            ->withCount([
                'articles as articles_count' => function (Builder $query) use ($context): Builder {
                    $query->published()->knowledge();

                    if ($context !== null) {
                        $this->accessFilter->apply($query, $context);
                    }

                    return $query;
                },
            ])
            ->get();

        $featuredArticlesQuery = KnowledgeArticle::query()
            ->published()
            ->knowledge()
            ->featured()
            ->with(['category', 'parent']);

        if ($context !== null) {
            $this->accessFilter->apply($featuredArticlesQuery, $context);
        }

        $featuredArticles = $featuredArticlesQuery
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->limit(6)
            ->get();

        $latestChangelog = KnowledgeArticle::query()
            ->published()
            ->changelog()
            ->orderByDesc('release_date')
            ->orderByDesc('published_at')
            ->limit(4)
            ->get();

        return [
            'categories' => $categories,
            'featured_articles' => $featuredArticles,
            'latest_changelog' => $latestChangelog,
            'summary' => [
                'categories_count' => $categories->count(),
                'articles_count' => $this->accessibleArticleQuery($context)->count(),
                'changelog_count' => KnowledgeArticle::query()->published()->changelog()->count(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function articles(array $filters, ?KnowledgeAccessContext $context = null): LengthAwarePaginator
    {
        $query = $this->accessibleArticleQuery($context)
            ->with(['category', 'parent']);

        $this->applyCommonFilters($query, $filters, includeChangelog: false);

        return $query
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->paginate(
                perPage: $this->perPage($filters),
                columns: ['*'],
                pageName: 'page',
                page: $this->page($filters),
            );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function changelog(array $filters): LengthAwarePaginator
    {
        $query = KnowledgeArticle::query()
            ->published()
            ->changelog()
            ->with('category');

        $this->applyCommonFilters($query, $filters, includeChangelog: true);

        return $query
            ->orderByDesc('release_date')
            ->orderByDesc('published_at')
            ->paginate(
                perPage: $this->perPage($filters),
                columns: ['*'],
                pageName: 'page',
                page: $this->page($filters),
            );
    }

    public function findArticleBySlug(string $slug, ?KnowledgeAccessContext $context = null): ?KnowledgeArticle
    {
        return $this->accessibleArticleQuery($context)
            ->with(['category', 'parent', 'children'])
            ->where('slug', $slug)
            ->first();
    }

    public function findArticleById(int $id, ?KnowledgeAccessContext $context = null): ?KnowledgeArticle
    {
        return $this->accessibleArticleQuery($context)
            ->with(['category', 'parent'])
            ->whereKey($id)
            ->first();
    }

    public function findChangelogBySlug(string $slug): ?KnowledgeArticle
    {
        return KnowledgeArticle::query()
            ->published()
            ->changelog()
            ->with('category')
            ->where('slug', $slug)
            ->first();
    }

    /**
     * @return Collection<int, KnowledgeArticle>
     */
    public function related(KnowledgeArticle $article, ?KnowledgeAccessContext $context = null): Collection
    {
        if ($article->category_id === null) {
            return collect();
        }

        return $this->accessibleArticleQuery($context)
            ->with(['category', 'parent'])
            ->where('id', '!=', $article->id)
            ->where('category_id', $article->category_id)
            ->orderByDesc('is_featured')
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyCommonFilters(Builder $query, array $filters, bool $includeChangelog): void
    {
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $this->fullTextSearch->apply($query, $search);
        }

        $category = trim((string) ($filters['category'] ?? ''));
        if ($category !== '') {
            $query->whereHas('category', fn (Builder $builder): Builder => $builder->where('slug', $category));
        }

        $tag = trim((string) ($filters['tag'] ?? ''));
        if ($tag !== '') {
            $query->whereJsonContains('tags', $tag);
        }

        $kind = trim((string) ($filters['kind'] ?? ''));
        if ($kind === '') {
            return;
        }

        if ($kind === KnowledgeArticleKind::CHANGELOG->value && ! $includeChangelog) {
            return;
        }

        if (KnowledgeArticleKind::tryFrom($kind) !== null) {
            $query->where('kind', $kind);
        }
    }

    private function accessibleArticleQuery(?KnowledgeAccessContext $context): Builder
    {
        $query = KnowledgeArticle::query()
            ->published()
            ->knowledge();

        if ($context !== null) {
            $this->accessFilter->apply($query, $context);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function perPage(array $filters): int
    {
        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);

        return min(max($perPage, 1), self::MAX_PER_PAGE);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function page(array $filters): int
    {
        return max((int) ($filters['page'] ?? 1), 1);
    }
}
