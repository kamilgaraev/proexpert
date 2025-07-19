<?php

namespace App\Repositories\Blog;

use App\Models\Blog\BlogTag;
use App\Repositories\BaseRepository;
use Illuminate\Support\Collection;

class BlogTagRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(BlogTag::class);
    }

    public function getActiveTags(): Collection
    {
        return $this->model
            ->active()
            ->orderBy('name')
            ->get();
    }

    public function getPopularTags(int $limit = 20): Collection
    {
        return $this->model
            ->active()
            ->popular()
            ->limit($limit)
            ->get();
    }

    public function findBySlug(string $slug)
    {
        return $this->model
            ->where('slug', $slug)
            ->active()
            ->first();
    }

    public function getOrCreateByName(string $name): BlogTag
    {
        return $this->model->firstOrCreate(
            ['name' => $name],
            ['slug' => \Illuminate\Support\Str::slug($name)]
        );
    }
} 