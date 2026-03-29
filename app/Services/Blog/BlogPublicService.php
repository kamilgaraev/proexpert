<?php

declare(strict_types=1);

namespace App\Services\Blog;

use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogCategory;
use App\Models\Blog\BlogTag;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class BlogPublicService
{
    public function getArticles(array $filters = []): LengthAwarePaginator
    {
        $perPage = max(1, min(24, (int) ($filters['per_page'] ?? 12)));
        $query = BlogArticle::query()
            ->marketing()
            ->published()
            ->with(['category', 'systemAuthor', 'author', 'tags']);

        if (!empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (!empty($filters['search'])) {
            $query->search((string) $filters['search']);
        }

        return $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function getArticleBySlug(string $slug): ?BlogArticle
    {
        return BlogArticle::query()
            ->marketing()
            ->published()
            ->with(['category', 'systemAuthor', 'author', 'tags'])
            ->where('slug', $slug)
            ->first();
    }

    public function getPreviewArticle(int $articleId): ?BlogArticle
    {
        return BlogArticle::query()
            ->marketing()
            ->with(['category', 'systemAuthor', 'author', 'tags'])
            ->find($articleId);
    }

    public function getPopularArticles(int $limit = 5): Collection
    {
        return BlogArticle::query()
            ->marketing()
            ->published()
            ->with(['category', 'systemAuthor', 'author', 'tags'])
            ->orderByDesc('views_count')
            ->orderByDesc('published_at')
            ->limit(max(1, min(12, $limit)))
            ->get();
    }

    public function getRelatedArticles(BlogArticle $article, int $limit = 3): Collection
    {
        $tagIds = $article->tags->pluck('id')->all();

        return BlogArticle::query()
            ->marketing()
            ->published()
            ->with(['category', 'systemAuthor', 'author', 'tags'])
            ->where('id', '!=', $article->id)
            ->when($tagIds !== [], function ($query) use ($tagIds): void {
                $query->whereHas('tags', fn ($tagQuery) => $tagQuery->whereIn('blog_tags.id', $tagIds));
            }, function ($query) use ($article): void {
                $query->where('category_id', $article->category_id);
            })
            ->orderByDesc('published_at')
            ->limit(max(1, min(12, $limit)))
            ->get();
    }

    public function getCategories(): Collection
    {
        return BlogCategory::query()
            ->marketing()
            ->active()
            ->withCount([
                'articles',
                'publishedArticles',
            ])
            ->ordered()
            ->get();
    }

    public function getTags(int $limit = 20): Collection
    {
        return BlogTag::query()
            ->marketing()
            ->active()
            ->popular()
            ->limit(max(1, min(50, $limit)))
            ->get();
    }

    public function search(string $query, int $limit = 10): Collection
    {
        return BlogArticle::query()
            ->marketing()
            ->published()
            ->with(['category', 'systemAuthor', 'author', 'tags'])
            ->search($query)
            ->orderByDesc('published_at')
            ->limit(max(1, min(50, $limit)))
            ->get();
    }
}
