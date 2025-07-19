<?php

namespace App\Http\Controllers\Api\V1\Landing\Blog;

use App\Http\Controllers\Controller;
use App\Models\Blog\BlogComment;
use App\Services\Blog\BlogCommentService;
use App\Repositories\Blog\BlogCommentRepository;
use App\Http\Requests\Api\V1\Landing\Blog\UpdateCommentStatusRequest;
use App\Http\Resources\Api\V1\Landing\Blog\BlogCommentResource;
use App\Http\Responses\Api\V1\SuccessResourceResponse;
use App\Http\Responses\Api\V1\ErrorResponse;
use App\Http\Responses\Api\V1\SuccessResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BlogCommentController extends Controller
{
    public function __construct(
        private BlogCommentService $commentService,
        private BlogCommentRepository $commentRepository
    ) {}

    public function index(Request $request)
    {
        try {
            $filters = [];
            
            if ($request->filled('status')) {
                $filters['status'] = $request->input('status');
            }
            
            if ($request->filled('article_id')) {
                $filters['article_id'] = $request->input('article_id');
            }
            
            $comments = $this->commentRepository->getAllCommentsPaginated(
                $filters,
                $request->input('per_page', 20)
            );
            
            return BlogCommentResource::collection($comments);
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при получении комментариев: ' . $e->getMessage(), 500);
        }
    }

    public function show(BlogComment $comment)
    {
        $comment->load(['article', 'parent', 'replies', 'approvedBy']);
        
        return new SuccessResourceResponse(
            new BlogCommentResource($comment)
        );
    }

    public function updateStatus(UpdateCommentStatusRequest $request, BlogComment $comment)
    {
        try {
            $admin = Auth::guard('api_landing_admin')->user();
            $status = $request->input('status');
            
            $updatedComment = match($status) {
                'approved' => $this->commentService->approveComment($comment, $admin),
                'rejected' => $this->commentService->rejectComment($comment),
                'spam' => $this->commentService->markAsSpam($comment),
                default => throw new \InvalidArgumentException('Неизвестный статус')
            };
            
            return new SuccessResourceResponse(
                new BlogCommentResource($updatedComment),
                'Статус комментария обновлен'
            );
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при обновлении статуса комментария: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(BlogComment $comment)
    {
        try {
            $this->commentService->deleteComment($comment);
            
            return new SuccessResponse(null, 'Комментарий удален');
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при удалении комментария: ' . $e->getMessage(), 500);
        }
    }

    public function bulkUpdateStatus(UpdateCommentStatusRequest $request)
    {
        $request->validate([
            'comment_ids' => 'required|array',
            'comment_ids.*' => 'integer|exists:blog_comments,id'
        ]);

        try {
            $admin = Auth::guard('api_landing_admin')->user();
            $commentIds = $request->input('comment_ids');
            $status = $request->input('status');
            
            $count = match($status) {
                'approved' => $this->commentService->bulkApprove($commentIds, $admin),
                'rejected' => $this->commentService->bulkReject($commentIds),
                'spam' => $this->commentService->bulkMarkAsSpam($commentIds),
                default => throw new \InvalidArgumentException('Неизвестный статус')
            };
            
            return new SuccessResponse(
                ['updated_count' => $count],
                "Обновлено комментариев: {$count}"
            );
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при массовом обновлении комментариев: ' . $e->getMessage(), 500);
        }
    }

    public function getPending()
    {
        try {
            $pendingComments = $this->commentRepository->getPendingComments();
            
            return new SuccessResourceResponse(
                BlogCommentResource::collection($pendingComments)
            );
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при получении ожидающих комментариев: ' . $e->getMessage(), 500);
        }
    }

    public function getRecent()
    {
        try {
            $recentComments = $this->commentRepository->getRecentComments(20);
            
            return new SuccessResourceResponse(
                BlogCommentResource::collection($recentComments)
            );
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при получении последних комментариев: ' . $e->getMessage(), 500);
        }
    }

    public function getStats()
    {
        try {
            $stats = $this->commentService->getCommentsStats();
            
            return new SuccessResponse($stats, 'Статистика комментариев');
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при получении статистики: ' . $e->getMessage(), 500);
        }
    }
} 