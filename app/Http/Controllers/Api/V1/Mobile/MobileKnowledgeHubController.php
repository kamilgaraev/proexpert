<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\BusinessModules\Features\KnowledgeHub\Http\Requests\KnowledgeArticleIndexRequest;
use App\BusinessModules\Features\KnowledgeHub\Http\Requests\KnowledgeContextRequest;
use App\BusinessModules\Features\KnowledgeHub\Http\Requests\KnowledgeFeedbackRequest;
use App\BusinessModules\Features\KnowledgeHub\Http\Requests\KnowledgeSearchRequest;
use App\BusinessModules\Features\KnowledgeHub\Http\Resources\KnowledgeArticleDetailResource;
use App\BusinessModules\Features\KnowledgeHub\Http\Resources\KnowledgeArticleListResource;
use App\BusinessModules\Features\KnowledgeHub\Http\Resources\KnowledgeArticleTreeResource;
use App\BusinessModules\Features\KnowledgeHub\Http\Resources\KnowledgeCategoryResource;
use App\BusinessModules\Features\KnowledgeHub\Http\Resources\KnowledgeContextHelpResource;
use App\BusinessModules\Features\KnowledgeHub\Http\Resources\KnowledgeSearchResultResource;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeAccessContextFactory;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeArticleTreeService;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeContextualHelpService;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeFeedbackService;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeHubQueryService;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeSearchAnalyticsService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class MobileKnowledgeHubController extends Controller
{
    public function __construct(
        private readonly KnowledgeHubQueryService $knowledgeHub,
        private readonly KnowledgeAccessContextFactory $contextFactory,
        private readonly KnowledgeArticleTreeService $treeService,
        private readonly KnowledgeContextualHelpService $contextualHelp,
        private readonly KnowledgeFeedbackService $feedbackService,
        private readonly KnowledgeSearchAnalyticsService $searchAnalytics,
    ) {
    }

    public function overview(Request $request): JsonResponse
    {
        try {
            $context = $this->contextFactory->fromRequest($request, KnowledgeSurface::MOBILE);
            $overview = $this->knowledgeHub->overview($context);

            return MobileResponse::success([
                'categories' => KnowledgeCategoryResource::collection($overview['categories'])->resolve($request),
                'featured_articles' => KnowledgeArticleListResource::collection($overview['featured_articles'])->resolve($request),
                'latest_changelog' => KnowledgeArticleListResource::collection($overview['latest_changelog'])->resolve($request),
                'summary' => $overview['summary'],
            ], trans_message('knowledge_hub.messages.overview_loaded'));
        } catch (Throwable $exception) {
            $this->reportFailure($request, $exception, 'overview');

            return MobileResponse::error(trans_message('knowledge_hub.messages.load_error'), 500);
        }
    }

    public function articles(KnowledgeArticleIndexRequest $request): JsonResponse
    {
        try {
            $context = $this->contextFactory->fromRequest($request, KnowledgeSurface::MOBILE);
            $paginator = $this->knowledgeHub->articles($request->validated(), $context);

            return MobileResponse::success(
                KnowledgeArticleListResource::collection($paginator->getCollection())->resolve($request),
                trans_message('knowledge_hub.messages.articles_loaded'),
                200,
                $this->paginationMeta($paginator),
            );
        } catch (Throwable $exception) {
            $this->reportFailure($request, $exception, 'articles', $request->validated());

            return MobileResponse::error(trans_message('knowledge_hub.messages.load_error'), 500);
        }
    }

    public function article(Request $request, string $slug): JsonResponse
    {
        try {
            $context = $this->contextFactory->fromRequest($request, KnowledgeSurface::MOBILE);
            $article = $this->knowledgeHub->findArticleBySlug($slug, $context);

            if ($article === null) {
                return MobileResponse::error(trans_message('knowledge_hub.messages.article_not_found'), 404);
            }

            return MobileResponse::success(
                (new KnowledgeArticleDetailResource($article, $this->knowledgeHub->related($article, $context)))->resolve($request),
                trans_message('knowledge_hub.messages.article_loaded'),
            );
        } catch (Throwable $exception) {
            $this->reportFailure($request, $exception, 'article', ['slug' => $slug]);

            return MobileResponse::error(trans_message('knowledge_hub.messages.load_error'), 500);
        }
    }

    public function tree(KnowledgeArticleIndexRequest $request): JsonResponse
    {
        try {
            $context = $this->contextFactory->fromRequest($request, KnowledgeSurface::MOBILE);

            return MobileResponse::success(
                KnowledgeArticleTreeResource::collection($this->treeService->tree($context, $request->validated()))->resolve($request),
                trans_message('knowledge_hub.messages.tree_loaded'),
            );
        } catch (Throwable $exception) {
            $this->reportFailure($request, $exception, 'tree', $request->validated());

            return MobileResponse::error(trans_message('knowledge_hub.messages.load_error'), 500);
        }
    }

    public function search(KnowledgeSearchRequest $request): JsonResponse
    {
        try {
            $payload = $request->validated();
            $context = $this->contextFactory->fromRequest($request, KnowledgeSurface::MOBILE);
            $paginator = $this->knowledgeHub->articles($payload, $context);

            $this->searchAnalytics->recordSearch(
                $context,
                (string) $payload['q'],
                $paginator->total(),
                isset($payload['clicked_article_id']) ? (int) $payload['clicked_article_id'] : null,
            );

            return MobileResponse::success(
                KnowledgeSearchResultResource::collection($paginator->getCollection())->resolve($request),
                trans_message('knowledge_hub.messages.search_loaded'),
                200,
                $this->paginationMeta($paginator),
            );
        } catch (Throwable $exception) {
            $this->reportFailure($request, $exception, 'search', $request->validated());

            return MobileResponse::error(trans_message('knowledge_hub.messages.load_error'), 500);
        }
    }

    public function context(KnowledgeContextRequest $request): JsonResponse
    {
        try {
            $payload = $request->validated();
            $context = $this->contextFactory->fromRequest($request, KnowledgeSurface::MOBILE);

            return MobileResponse::success(
                (new KnowledgeContextHelpResource($this->contextualHelp->resolve($context, (int) ($payload['limit'] ?? 4))))->resolve($request),
                trans_message('knowledge_hub.messages.context_loaded'),
            );
        } catch (Throwable $exception) {
            $this->reportFailure($request, $exception, 'context', $request->validated());

            return MobileResponse::error(trans_message('knowledge_hub.messages.load_error'), 500);
        }
    }

    public function feedback(KnowledgeFeedbackRequest $request): JsonResponse
    {
        try {
            $payload = $request->validated();
            $context = $this->contextFactory->fromRequest($request, KnowledgeSurface::MOBILE);
            $article = $this->knowledgeHub->findArticleById((int) $payload['article_id'], $context);

            if ($article === null) {
                return MobileResponse::error(trans_message('knowledge_hub.messages.article_not_found'), 404);
            }

            $feedback = $this->feedbackService->store($context, $payload);

            return MobileResponse::success(['id' => $feedback->id], trans_message('knowledge_hub.messages.feedback_saved'));
        } catch (Throwable $exception) {
            $this->reportFailure($request, $exception, 'feedback', $request->validated());

            return MobileResponse::error(trans_message('knowledge_hub.messages.feedback_error'), 500);
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
    private function reportFailure(Request $request, Throwable $exception, string $action, array $context = []): void
    {
        Log::error('Mobile knowledge hub request failed.', [
            'action' => $action,
            'user_id' => $request->user()?->id,
            'organization_id' => $request->user()?->current_organization_id,
            'context' => $context,
            'exception' => $exception,
        ]);
    }
}
