<?php

namespace App\Http\Controllers\Api\V1\Landing\Blog;

use App\Http\Controllers\Controller;
use App\Models\Blog\BlogArticle;
use App\Services\Blog\BlogArticleService;
use App\Repositories\Blog\BlogArticleRepository;
use App\Http\Requests\Api\V1\Landing\Blog\StoreArticleRequest;
use App\Http\Requests\Api\V1\Landing\Blog\UpdateArticleRequest;
use App\Http\Resources\Api\V1\Landing\Blog\BlogArticleResource;
use App\Http\Responses\Api\V1\SuccessResourceResponse;
use App\Http\Responses\Api\V1\ErrorResponse;
use App\Http\Responses\Api\V1\SuccessResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class BlogArticleController extends Controller
{
    public function __construct(
        private BlogArticleService $articleService,
        private BlogArticleRepository $articleRepository
    ) {}

    public function index(Request $request)
    {
        $filters = [];
        
        if ($request->filled('status')) {
            $filters['status'] = $request->input('status');
        }
        
        if ($request->filled('category_id')) {
            $filters['category_id'] = $request->input('category_id');
        }
        
        if ($request->filled('author_id')) {
            $filters['author_id'] = $request->input('author_id');
        }
        
        if ($request->filled('search')) {
            $search = $request->input('search');
            $articles = $this->articleRepository->searchArticles($search, $request->input('per_page', 15));
        } else {
            $articles = $this->articleRepository->getAllPaginated(
                $filters,
                $request->input('per_page', 15),
                'updated_at',
                'desc',
                ['category', 'author', 'tags']
            );
        }

        return BlogArticleResource::collection($articles);
    }

    public function store(StoreArticleRequest $request)
    {
        try {
            $admin = Auth::guard('api_landing_admin')->user();
            $article = $this->articleService->createArticle($request->validated(), $admin);
            
            return new SuccessResourceResponse(
                new BlogArticleResource($article),
                'Статья успешно создана'
            );
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при создании статьи: ' . $e->getMessage(), 500);
        }
    }

    public function show(BlogArticle $article)
    {
        $article->load(['category', 'author', 'tags', 'comments']);
        
        return new SuccessResourceResponse(
            new BlogArticleResource($article)
        );
    }

    public function update(UpdateArticleRequest $request, BlogArticle $article)
    {
        try {
            $updatedArticle = $this->articleService->updateArticle($article, $request->validated());
            
            return new SuccessResourceResponse(
                new BlogArticleResource($updatedArticle),
                'Статья успешно обновлена'
            );
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при обновлении статьи: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(BlogArticle $article)
    {
        try {
            $article->delete();
            
            return new SuccessResponse(null, 'Статья успешно удалена');
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при удалении статьи: ' . $e->getMessage(), 500);
        }
    }

    public function publish(BlogArticle $article, Request $request)
    {
        try {
            $publishAt = $request->input('publish_at') ? Carbon::parse($request->input('publish_at')) : null;
            $publishedArticle = $this->articleService->publishArticle($article, $publishAt);
            
            return new SuccessResourceResponse(
                new BlogArticleResource($publishedArticle),
                'Статья опубликована'
            );
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при публикации статьи: ' . $e->getMessage(), 500);
        }
    }

    public function schedule(Request $request, BlogArticle $article)
    {
        $request->validate([
            'scheduled_at' => 'required|date|after:now'
        ]);

        try {
            $scheduledArticle = $this->articleService->scheduleArticle(
                $article, 
                Carbon::parse($request->input('scheduled_at'))
            );
            
            return new SuccessResourceResponse(
                new BlogArticleResource($scheduledArticle),
                'Статья запланирована к публикации'
            );
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при планировании статьи: ' . $e->getMessage(), 500);
        }
    }

    public function archive(BlogArticle $article)
    {
        try {
            $archivedArticle = $this->articleService->archiveArticle($article);
            
            return new SuccessResourceResponse(
                new BlogArticleResource($archivedArticle),
                'Статья архивирована'
            );
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при архивировании статьи: ' . $e->getMessage(), 500);
        }
    }

    public function duplicate(BlogArticle $article)
    {
        try {
            $admin = Auth::guard('api_landing_admin')->user();
            $duplicatedArticle = $this->articleService->duplicateArticle($article, $admin);
            
            return new SuccessResourceResponse(
                new BlogArticleResource($duplicatedArticle),
                'Статья успешно дублирована'
            );
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при дублировании статьи: ' . $e->getMessage(), 500);
        }
    }

    public function generateSeoData(BlogArticle $article)
    {
        try {
            $seoData = $this->articleService->generateSeoData($article);
            
            return new SuccessResponse($seoData, 'SEO данные сгенерированы');
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при генерации SEO данных: ' . $e->getMessage(), 500);
        }
    }

    public function getScheduled()
    {
        try {
            $scheduledArticles = $this->articleRepository->getScheduledArticles();
            
            return new SuccessResourceResponse(
                BlogArticleResource::collection($scheduledArticles)
            );
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при получении запланированных статей: ' . $e->getMessage(), 500);
        }
    }

    public function getDrafts(Request $request)
    {
        try {
            $admin = Auth::guard('api_landing_admin')->user();
            $drafts = $this->articleRepository->getDraftsByAuthor($admin->id);
            
            return new SuccessResourceResponse(
                BlogArticleResource::collection($drafts)
            );
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при получении черновиков: ' . $e->getMessage(), 500);
        }
    }
} 