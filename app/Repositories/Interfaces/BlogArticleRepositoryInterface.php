<?php

namespace App\Repositories\Interfaces;

use App\Models\Blog\BlogArticle;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface BlogArticleRepositoryInterface extends BaseRepositoryInterface
{
    public function getPublishedArticles(int $perPage = 15): LengthAwarePaginator;
    
    public function getFeaturedArticles(int $limit = 5): Collection;
    
    public function getArticlesByCategory(int $categoryId, int $perPage = 15): LengthAwarePaginator;
    
    public function getArticlesByTag(int $tagId, int $perPage = 15): LengthAwarePaginator;
    
    public function searchArticles(string $query, int $perPage = 15): LengthAwarePaginator;
    
    public function getPopularArticles(int $limit = 10): Collection;
    
    public function getRelatedArticles(BlogArticle $article, int $limit = 5): Collection;
    
    public function findBySlug(string $slug): ?BlogArticle;
    
    public function getScheduledArticles(): Collection;
    
    public function getDraftsByAuthor(int $authorId): Collection;
} 