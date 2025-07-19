<?php

namespace App\Repositories\Blog;

use App\Models\Blog\BlogComment;
use App\Repositories\BaseRepository;
use App\Enums\Blog\BlogCommentStatusEnum;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class BlogCommentRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(BlogComment::class);
    }

    public function getCommentsByArticle(int $articleId): Collection
    {
        return $this->model
            ->with(['replies' => function ($query) {
                $query->approved()->orderBy('created_at');
            }])
            ->where('article_id', $articleId)
            ->approved()
            ->rootComments()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getPendingComments(): Collection
    {
        return $this->model
            ->with(['article'])
            ->pending()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getAllCommentsPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->model->with(['article', 'parent']);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['article_id'])) {
            $query->where('article_id', $filters['article_id']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function getRecentComments(int $limit = 10): Collection
    {
        return $this->model
            ->with(['article'])
            ->approved()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
} 