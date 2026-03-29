<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Blog;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Blog\PublicBlogArticleResource;
use App\Http\Resources\Api\V1\Blog\PublicBlogCategoryResource;
use App\Http\Resources\Api\V1\Blog\PublicBlogTagResource;
use App\Http\Responses\LandingResponse;
use App\Models\Blog\BlogArticle;
use App\Services\Blog\BlogPublicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PublicBlogController extends Controller
{
    public function __construct(
        private readonly BlogPublicService $blogPublicService,
    ) {
    }

    public function articles(Request $request): JsonResponse
    {
        try {
            $articles = $this->blogPublicService->getArticles($request->only(['category_id', 'search', 'per_page']));

            return LandingResponse::success([
                'data' => PublicBlogArticleResource::collection($articles->getCollection())->resolve(),
                'meta' => [
                    'current_page' => $articles->currentPage(),
                    'last_page' => $articles->lastPage(),
                    'per_page' => $articles->perPage(),
                    'total' => $articles->total(),
                ],
                'links' => [
                    'first' => $articles->url(1),
                    'last' => $articles->url($articles->lastPage()),
                    'prev' => $articles->previousPageUrl(),
                    'next' => $articles->nextPageUrl(),
                ],
            ], trans_message('blog_cms.articles_loaded'));
        } catch (\Throwable $e) {
            Log::error('Public blog articles load failed', [
                'filters' => $request->all(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('blog_cms.article_not_found'), 500);
        }
    }

    public function article(string $slug): JsonResponse
    {
        try {
            $article = $this->blogPublicService->getArticleBySlug($slug);

            if (!$article instanceof BlogArticle) {
                return LandingResponse::error(trans_message('blog_cms.article_not_found'), 404);
            }

            $article->incrementViews();

            return LandingResponse::success(
                new PublicBlogArticleResource($article->fresh(['category', 'systemAuthor', 'author', 'tags'])),
                trans_message('blog_cms.article_loaded'),
            );
        } catch (\Throwable $e) {
            Log::error('Public blog article load failed', [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('blog_cms.article_not_found'), 500);
        }
    }

    public function preview(Request $request, int $article): JsonResponse
    {
        try {
            if (!$request->hasValidSignature()) {
                return LandingResponse::error(trans_message('blog_cms.preview_forbidden'), 403);
            }

            $record = $this->blogPublicService->getPreviewArticle($article);

            if (!$record instanceof BlogArticle) {
                return LandingResponse::error(trans_message('blog_cms.preview_not_found'), 404);
            }

            return LandingResponse::success(
                new PublicBlogArticleResource($record),
                trans_message('blog_cms.preview_loaded'),
            );
        } catch (\Throwable $e) {
            Log::error('Public blog preview load failed', [
                'article_id' => $article,
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('blog_cms.preview_not_found'), 500);
        }
    }

    public function popular(Request $request): JsonResponse
    {
        try {
            $limit = (int) $request->integer('limit', 5);

            return LandingResponse::success(
                PublicBlogArticleResource::collection($this->blogPublicService->getPopularArticles($limit)),
                trans_message('blog_cms.articles_loaded'),
            );
        } catch (\Throwable $e) {
            Log::error('Public blog popular articles load failed', [
                'limit' => $request->get('limit'),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('blog_cms.article_not_found'), 500);
        }
    }

    public function related(Request $request, int $article): JsonResponse
    {
        try {
            $record = $this->blogPublicService->getPreviewArticle($article);

            if (!$record instanceof BlogArticle || !$record->is_published) {
                return LandingResponse::error(trans_message('blog_cms.article_not_found'), 404);
            }

            return LandingResponse::success(
                PublicBlogArticleResource::collection($this->blogPublicService->getRelatedArticles($record, (int) $request->integer('limit', 3))),
                trans_message('blog_cms.articles_loaded'),
            );
        } catch (\Throwable $e) {
            Log::error('Public blog related articles load failed', [
                'article_id' => $article,
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('blog_cms.article_not_found'), 500);
        }
    }

    public function categories(): JsonResponse
    {
        try {
            return LandingResponse::success(
                PublicBlogCategoryResource::collection($this->blogPublicService->getCategories()),
                trans_message('blog_cms.categories_loaded'),
            );
        } catch (\Throwable $e) {
            Log::error('Public blog categories load failed', [
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('blog_cms.article_not_found'), 500);
        }
    }

    public function tags(Request $request): JsonResponse
    {
        try {
            return LandingResponse::success(
                PublicBlogTagResource::collection($this->blogPublicService->getTags((int) $request->integer('limit', 20))),
                trans_message('blog_cms.tags_loaded'),
            );
        } catch (\Throwable $e) {
            Log::error('Public blog tags load failed', [
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('blog_cms.article_not_found'), 500);
        }
    }

    public function search(Request $request): JsonResponse
    {
        try {
            $query = trim((string) $request->string('q'));

            return LandingResponse::success(
                PublicBlogArticleResource::collection($this->blogPublicService->search($query, (int) $request->integer('limit', 10))),
                trans_message('blog_cms.search_loaded'),
            );
        } catch (\Throwable $e) {
            Log::error('Public blog search failed', [
                'query' => $request->get('q'),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('blog_cms.article_not_found'), 500);
        }
    }
}
