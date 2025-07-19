<?php

namespace App\Services\Blog;

use App\Models\Blog\BlogComment;
use App\Models\Blog\BlogArticle;
use App\Models\LandingAdmin;
use App\Repositories\Blog\BlogCommentRepository;
use App\Enums\Blog\BlogCommentStatusEnum;
use Illuminate\Support\Facades\DB;

class BlogCommentService
{
    public function __construct(
        private BlogCommentRepository $commentRepository
    ) {}

    public function createComment(array $data): BlogComment
    {
        return DB::transaction(function () use ($data) {
            $comment = BlogComment::create($data);
            
            $this->updateArticleCommentsCount($comment->article_id);
            
            return $comment;
        });
    }

    public function approveComment(BlogComment $comment, LandingAdmin $admin): BlogComment
    {
        $comment->approve($admin);
        
        $this->updateArticleCommentsCount($comment->article_id);
        
        return $comment;
    }

    public function rejectComment(BlogComment $comment): BlogComment
    {
        $comment->reject();
        
        $this->updateArticleCommentsCount($comment->article_id);
        
        return $comment;
    }

    public function markAsSpam(BlogComment $comment): BlogComment
    {
        $comment->markAsSpam();
        
        $this->updateArticleCommentsCount($comment->article_id);
        
        return $comment;
    }

    public function deleteComment(BlogComment $comment): bool
    {
        return DB::transaction(function () use ($comment) {
            $articleId = $comment->article_id;
            
            $comment->replies()->delete();
            $result = $comment->delete();
            
            $this->updateArticleCommentsCount($articleId);
            
            return $result;
        });
    }

    public function bulkApprove(array $commentIds, LandingAdmin $admin): int
    {
        $comments = BlogComment::whereIn('id', $commentIds)->get();
        $approvedCount = 0;
        
        foreach ($comments as $comment) {
            if ($comment->status === BlogCommentStatusEnum::PENDING) {
                $this->approveComment($comment, $admin);
                $approvedCount++;
            }
        }
        
        return $approvedCount;
    }

    public function bulkReject(array $commentIds): int
    {
        $comments = BlogComment::whereIn('id', $commentIds)->get();
        $rejectedCount = 0;
        
        foreach ($comments as $comment) {
            if ($comment->status === BlogCommentStatusEnum::PENDING) {
                $this->rejectComment($comment);
                $rejectedCount++;
            }
        }
        
        return $rejectedCount;
    }

    public function bulkMarkAsSpam(array $commentIds): int
    {
        $comments = BlogComment::whereIn('id', $commentIds)->get();
        $spamCount = 0;
        
        foreach ($comments as $comment) {
            $this->markAsSpam($comment);
            $spamCount++;
        }
        
        return $spamCount;
    }

    public function getCommentsStats(): array
    {
        return [
            'total' => BlogComment::count(),
            'pending' => BlogComment::where('status', BlogCommentStatusEnum::PENDING)->count(),
            'approved' => BlogComment::where('status', BlogCommentStatusEnum::APPROVED)->count(),
            'rejected' => BlogComment::where('status', BlogCommentStatusEnum::REJECTED)->count(),
            'spam' => BlogComment::where('status', BlogCommentStatusEnum::SPAM)->count(),
        ];
    }

    private function updateArticleCommentsCount(int $articleId): void
    {
        $count = BlogComment::where('article_id', $articleId)
            ->where('status', BlogCommentStatusEnum::APPROVED)
            ->count();
            
        BlogArticle::where('id', $articleId)->update(['comments_count' => $count]);
    }
} 