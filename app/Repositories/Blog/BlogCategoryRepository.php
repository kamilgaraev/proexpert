<?php

namespace App\Repositories\Blog;

use App\Models\Blog\BlogCategory;
use App\Repositories\BaseRepository;
use App\Repositories\Interfaces\BlogCategoryRepositoryInterface;
use Illuminate\Support\Collection;

class BlogCategoryRepository extends BaseRepository implements BlogCategoryRepositoryInterface
{
    public function __construct()
    {
        parent::__construct(BlogCategory::class);
    }

    public function getActiveCategories(): Collection
    {
        return $this->model
            ->active()
            ->ordered()
            ->get();
    }

    public function getCategoriesWithArticleCount(): Collection
    {
        return $this->model
            ->active()
            ->withCount(['publishedArticles'])
            ->ordered()
            ->get();
    }

    public function findBySlug(string $slug)
    {
        return $this->model
            ->where('slug', $slug)
            ->active()
            ->first();
    }
} 