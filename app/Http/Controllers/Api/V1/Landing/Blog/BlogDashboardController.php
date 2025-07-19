<?php

namespace App\Http\Controllers\Api\V1\Landing\Blog;

use App\Http\Controllers\Controller;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogComment;
use App\Models\Blog\BlogCategory;
use App\Models\Blog\BlogTag;
use App\Services\Blog\BlogCommentService;
use App\Repositories\Blog\BlogArticleRepository;
use App\Http\Resources\Api\V1\Landing\Blog\BlogArticleResource;
use App\Http\Resources\Api\V1\Landing\Blog\BlogCommentResource;
use App\Http\Responses\Api\V1\SuccessResponse;
use App\Http\Responses\Api\V1\ErrorResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BlogDashboardController extends Controller
{
    public function __construct(
        private BlogArticleRepository $articleRepository,
        private BlogCommentService $commentService
    ) {}

    public function overview()
    {
        try {
            $data = [
                'articles' => [
                    'total' => BlogArticle::count(),
                    'published' => BlogArticle::where('status', 'published')->count(),
                    'drafts' => BlogArticle::where('status', 'draft')->count(),
                    'scheduled' => BlogArticle::where('status', 'scheduled')->count(),
                ],
                'categories' => [
                    'total' => BlogCategory::count(),
                    'active' => BlogCategory::where('is_active', true)->count(),
                ],
                'tags' => [
                    'total' => BlogTag::count(),
                    'active' => BlogTag::where('is_active', true)->count(),
                ],
                'comments' => $this->commentService->getCommentsStats(),
                'popular_articles' => BlogArticleResource::collection(
                    $this->articleRepository->getPopularArticles(5)
                ),
                'recent_articles' => BlogArticleResource::collection(
                    BlogArticle::with(['category', 'author'])
                        ->orderBy('created_at', 'desc')
                        ->limit(5)
                        ->get()
                ),
                'recent_comments' => BlogCommentResource::collection(
                    BlogComment::with(['article', 'approvedBy'])
                        ->orderBy('created_at', 'desc')
                        ->limit(5)
                        ->get()
                ),
            ];

            return new SuccessResponse($data, 'Обзор блога');
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при получении обзора: ' . $e->getMessage(), 500);
        }
    }

    public function analytics(Request $request)
    {
        $request->validate([
            'period' => 'sometimes|string|in:week,month,quarter,year',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after:start_date',
        ]);

        try {
            $period = $request->input('period', 'month');
            $startDate = $request->input('start_date') 
                ? Carbon::parse($request->input('start_date'))
                : Carbon::now()->subMonth();
            $endDate = $request->input('end_date')
                ? Carbon::parse($request->input('end_date'))
                : Carbon::now();

            $data = [
                'articles_by_date' => $this->getArticlesByDate($startDate, $endDate),
                'comments_by_date' => $this->getCommentsByDate($startDate, $endDate),
                'views_by_date' => $this->getViewsByDate($startDate, $endDate),
                'categories_stats' => $this->getCategoriesStats(),
                'tags_stats' => $this->getTagsStats(),
                'authors_stats' => $this->getAuthorsStats(),
                'top_articles' => $this->getTopArticles($startDate, $endDate),
            ];

            return new SuccessResponse($data, 'Аналитика блога');
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при получении аналитики: ' . $e->getMessage(), 500);
        }
    }

    public function quickStats()
    {
        try {
            $today = Carbon::today();
            $yesterday = Carbon::yesterday();
            $thisMonth = Carbon::now()->startOfMonth();
            $lastMonth = Carbon::now()->subMonth()->startOfMonth();

            $data = [
                'today' => [
                    'articles' => BlogArticle::whereDate('created_at', $today)->count(),
                    'comments' => BlogComment::whereDate('created_at', $today)->count(),
                    'views' => BlogArticle::whereDate('updated_at', $today)->sum('views_count'),
                ],
                'yesterday' => [
                    'articles' => BlogArticle::whereDate('created_at', $yesterday)->count(),
                    'comments' => BlogComment::whereDate('created_at', $yesterday)->count(),
                    'views' => BlogArticle::whereDate('updated_at', $yesterday)->sum('views_count'),
                ],
                'this_month' => [
                    'articles' => BlogArticle::where('created_at', '>=', $thisMonth)->count(),
                    'comments' => BlogComment::where('created_at', '>=', $thisMonth)->count(),
                    'views' => BlogArticle::where('updated_at', '>=', $thisMonth)->sum('views_count'),
                ],
                'last_month' => [
                    'articles' => BlogArticle::whereBetween('created_at', [$lastMonth, $thisMonth])->count(),
                    'comments' => BlogComment::whereBetween('created_at', [$lastMonth, $thisMonth])->count(),
                    'views' => BlogArticle::whereBetween('updated_at', [$lastMonth, $thisMonth])->sum('views_count'),
                ],
            ];

            return new SuccessResponse($data, 'Быстрая статистика');
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при получении статистики: ' . $e->getMessage(), 500);
        }
    }

    private function getArticlesByDate(Carbon $startDate, Carbon $endDate): array
    {
        return BlogArticle::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count')
            )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    private function getCommentsByDate(Carbon $startDate, Carbon $endDate): array
    {
        return BlogComment::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count')
            )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    private function getViewsByDate(Carbon $startDate, Carbon $endDate): array
    {
        return BlogArticle::select(
                DB::raw('DATE(updated_at) as date'),
                DB::raw('SUM(views_count) as total_views')
            )
            ->whereBetween('updated_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    private function getCategoriesStats(): array
    {
        return BlogCategory::select('name', 'id')
            ->withCount(['publishedArticles'])
            ->orderBy('published_articles_count', 'desc')
            ->get()
            ->toArray();
    }

    private function getTagsStats(): array
    {
        return BlogTag::select('name', 'usage_count')
            ->where('usage_count', '>', 0)
            ->orderBy('usage_count', 'desc')
            ->limit(20)
            ->get()
            ->toArray();
    }

    private function getAuthorsStats(): array
    {
        return BlogArticle::select('author_id')
            ->with('author:id,name')
            ->selectRaw('COUNT(*) as articles_count')
            ->selectRaw('SUM(views_count) as total_views')
            ->selectRaw('SUM(likes_count) as total_likes')
            ->groupBy('author_id')
            ->orderBy('articles_count', 'desc')
            ->get()
            ->toArray();
    }

    private function getTopArticles(Carbon $startDate, Carbon $endDate): array
    {
        return BlogArticle::with(['category', 'author'])
            ->whereBetween('published_at', [$startDate, $endDate])
            ->orderBy('views_count', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($article) {
                return [
                    'id' => $article->id,
                    'title' => $article->title,
                    'views_count' => $article->views_count,
                    'likes_count' => $article->likes_count,
                    'comments_count' => $article->comments_count,
                    'category' => $article->category?->name,
                    'author' => $article->author?->name,
                    'published_at' => $article->published_at?->toISOString(),
                ];
            })
            ->toArray();
    }
} 