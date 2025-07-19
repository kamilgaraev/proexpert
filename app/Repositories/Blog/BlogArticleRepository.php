<?php

namespace App\Repositories\Blog;

use App\Models\Blog\BlogArticle;
use App\Repositories\BaseRepository;
use App\Repositories\Interfaces\BlogArticleRepositoryInterface;
use App\Enums\Blog\BlogArticleStatusEnum;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class BlogArticleRepository extends BaseRepository implements BlogArticleRepositoryInterface
{
    public function __construct()
    {
        parent::__construct(BlogArticle::class);
    }

    public function getPublishedArticles(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->with(['category', 'author', 'tags'])
            ->published()
            ->orderBy('published_at', 'desc')
            ->paginate($perPage);
    }

    public function getFeaturedArticles(int $limit = 5): Collection
    {
        return $this->model
            ->with(['category', 'author', 'tags'])
            ->published()
            ->featured()
            ->orderBy('published_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function getArticlesByCategory(int $categoryId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->with(['category', 'author', 'tags'])
            ->published()
            ->byCategory($categoryId)
            ->orderBy('published_at', 'desc')
            ->paginate($perPage);
    }

    public function getArticlesByTag(int $tagId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->with(['category', 'author', 'tags'])
            ->published()
            ->byTag($tagId)
            ->orderBy('published_at', 'desc')
            ->paginate($perPage);
    }

    public function searchArticles(string $query, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->with(['category', 'author', 'tags'])
            ->published()
            ->search($query)
            ->orderBy('published_at', 'desc')
            ->paginate($perPage);
    }

    public function getPopularArticles(int $limit = 10): Collection
    {
        return $this->model
            ->with(['category', 'author', 'tags'])
            ->published()
            ->orderBy('views_count', 'desc')
            ->limit($limit)
            ->get();
    }

    public function getRelatedArticles(BlogArticle $article, int $limit = 5): Collection
    {
        $tagIds = $article->tags->pluck('id')->toArray();
        
        $query = $this->model
            ->with(['category', 'author', 'tags'])
            ->published()
            ->where('id', '!=', $article->id);

        if (!empty($tagIds)) {
            $query->whereHas('tags', function ($q) use ($tagIds) {
                $q->whereIn('blog_tags.id', $tagIds);
            });
        } else {
            $query->where('category_id', $article->category_id);
        }

        return $query
            ->orderBy('published_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function findBySlug(string $slug): ?BlogArticle
    {
        return $this->model
            ->with(['category', 'author', 'tags', 'approvedComments.parent'])
            ->where('slug', $slug)
            ->first();
    }

    public function getScheduledArticles(): Collection
    {
        return $this->model
            ->with(['category', 'author'])
            ->scheduled()
            ->orderBy('scheduled_at')
            ->get();
    }

    public function getDraftsByAuthor(int $authorId): Collection
    {
        return $this->model
            ->with(['category', 'tags'])
            ->where('author_id', $authorId)
            ->where('status', BlogArticleStatusEnum::DRAFT)
            ->orderBy('updated_at', 'desc')
            ->get();
    }
} 