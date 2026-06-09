<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\BusinessModules\Features\KnowledgeHub\Http\Requests\KnowledgeArticleIndexRequest;
use App\BusinessModules\Features\KnowledgeHub\Http\Resources\KnowledgeArticleDetailResource;
use App\BusinessModules\Features\KnowledgeHub\Http\Resources\KnowledgeArticleListResource;
use App\BusinessModules\Features\KnowledgeHub\Http\Resources\KnowledgeCategoryResource;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeHubQueryService;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class KnowledgeHubController extends Controller
{
    public function __construct(private readonly KnowledgeHubQueryService $knowledgeHub)
    {
    }

    public function overview(Request $request): JsonResponse
    {
        try {
            $overview = $this->knowledgeHub->overview();

            return LandingResponse::success([
                'categories' => KnowledgeCategoryResource::collection($overview['categories'])->resolve($request),
                'featured_articles' => KnowledgeArticleListResource::collection($overview['featured_articles'])->resolve($request),
                'latest_changelog' => KnowledgeArticleListResource::collection($overview['latest_changelog'])->resolve($request),
                'summary' => $overview['summary'],
            ], trans_message('knowledge_hub.messages.overview_loaded'));
        } catch (Throwable $exception) {
            $this->reportFailure($exception, 'overview');

            return LandingResponse::error(trans_message('knowledge_hub.messages.load_error'), 500);
        }
    }

    public function articles(KnowledgeArticleIndexRequest $request): JsonResponse
    {
        try {
            $paginator = $this->knowledgeHub->articles($request->validated());

            return LandingResponse::paginated(
                KnowledgeArticleListResource::collection($paginator->getCollection())->resolve($request),
                $this->paginationMeta($paginator),
                trans_message('knowledge_hub.messages.articles_loaded'),
            );
        } catch (Throwable $exception) {
            $this->reportFailure($exception, 'articles', $request->validated());

            return LandingResponse::error(trans_message('knowledge_hub.messages.load_error'), 500);
        }
    }

    public function article(Request $request, string $slug): JsonResponse
    {
        try {
            $article = $this->knowledgeHub->findArticleBySlug($slug);

            if ($article === null) {
                return LandingResponse::error(trans_message('knowledge_hub.messages.article_not_found'), 404);
            }

            return LandingResponse::success(
                (new KnowledgeArticleDetailResource($article, $this->knowledgeHub->related($article)))->resolve($request),
                trans_message('knowledge_hub.messages.article_loaded'),
            );
        } catch (Throwable $exception) {
            $this->reportFailure($exception, 'article', ['slug' => $slug]);

            return LandingResponse::error(trans_message('knowledge_hub.messages.load_error'), 500);
        }
    }

    public function changelog(KnowledgeArticleIndexRequest $request): JsonResponse
    {
        try {
            $paginator = $this->knowledgeHub->changelog($request->validated());

            return LandingResponse::paginated(
                KnowledgeArticleListResource::collection($paginator->getCollection())->resolve($request),
                $this->paginationMeta($paginator),
                trans_message('knowledge_hub.messages.changelog_loaded'),
            );
        } catch (Throwable $exception) {
            $this->reportFailure($exception, 'changelog', $request->validated());

            return LandingResponse::error(trans_message('knowledge_hub.messages.load_error'), 500);
        }
    }

    public function changelogEntry(Request $request, string $slug): JsonResponse
    {
        try {
            $entry = $this->knowledgeHub->findChangelogBySlug($slug);

            if ($entry === null) {
                return LandingResponse::error(trans_message('knowledge_hub.messages.article_not_found'), 404);
            }

            return LandingResponse::success(
                (new KnowledgeArticleDetailResource($entry))->resolve($request),
                trans_message('knowledge_hub.messages.article_loaded'),
            );
        } catch (Throwable $exception) {
            $this->reportFailure($exception, 'changelogEntry', ['slug' => $slug]);

            return LandingResponse::error(trans_message('knowledge_hub.messages.load_error'), 500);
        }
    }

    /**
     * @return array<string, int>
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function reportFailure(Throwable $exception, string $action, array $context = []): void
    {
        Log::error('Knowledge hub request failed.', [
            'action' => $action,
            'user_id' => Auth::id(),
            'context' => $context,
            'exception' => $exception,
        ]);
    }
}
