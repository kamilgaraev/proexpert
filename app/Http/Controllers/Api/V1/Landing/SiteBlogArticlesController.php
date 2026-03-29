<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\HoldingSiteBlogService;
use App\Enums\Blog\BlogContextEnum;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\Blog\BlogArticle;
use App\Models\OrganizationGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SiteBlogArticlesController extends Controller
{
    public function __construct(
        private readonly HoldingSiteBlogService $blogService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $group = $this->resolveHoldingGroup($request);
            $site = $this->resolveHoldingSite($group);

            if (!$site->canUserView(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            return LandingResponse::success($this->blogService->listArticles($group, false));
        } catch (\Throwable $e) {
            Log::error('Holding site blog articles load failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.load_error'), 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $group = $this->resolveHoldingGroup($request);
            $site = $this->resolveHoldingSite($group);

            if (!$site->canUserEdit(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'slug' => 'nullable|string|max:255',
                'excerpt' => 'nullable|string|max:2000',
                'content' => 'nullable|string',
                'featured_image' => 'nullable|string|max:2048',
                'status' => 'nullable|string|in:draft,published,scheduled,archived',
                'meta_title' => 'nullable|string|max:255',
                'meta_description' => 'nullable|string|max:500',
                'meta_keywords' => 'nullable|array',
                'category_id' => 'nullable|integer',
                'is_featured' => 'nullable|boolean',
                'noindex' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return LandingResponse::error(trans_message('holding_site_builder.validation_error'), 422, $validator->errors());
            }

            $article = $this->blogService->createArticle($group, $validator->validated(), Auth::user());

            return LandingResponse::success($this->blogService->serializeArticle($article), trans_message('holding_site_builder.updated'), 201);
        } catch (\Throwable $e) {
            Log::error('Holding site blog article create failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.update_error'), 500);
        }
    }

    public function update(Request $request, int $articleId): JsonResponse
    {
        try {
            $group = $this->resolveHoldingGroup($request);
            $site = $this->resolveHoldingSite($group);
            $article = BlogArticle::query()
                ->where('blog_context', BlogContextEnum::HOLDING->value)
                ->where('organization_group_id', $group->id)
                ->where('id', $articleId)
                ->firstOrFail();

            if (!$site->canUserEdit(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $validator = Validator::make($request->all(), [
                'title' => 'nullable|string|max:255',
                'slug' => 'nullable|string|max:255',
                'excerpt' => 'nullable|string|max:2000',
                'content' => 'nullable|string',
                'featured_image' => 'nullable|string|max:2048',
                'status' => 'nullable|string|in:draft,published,scheduled,archived',
                'meta_title' => 'nullable|string|max:255',
                'meta_description' => 'nullable|string|max:500',
                'meta_keywords' => 'nullable|array',
                'category_id' => 'nullable|integer',
                'is_featured' => 'nullable|boolean',
                'noindex' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return LandingResponse::error(trans_message('holding_site_builder.validation_error'), 422, $validator->errors());
            }

            $updated = $this->blogService->updateArticle($article, $validator->validated(), Auth::user());

            return LandingResponse::success($this->blogService->serializeArticle($updated), trans_message('holding_site_builder.updated'));
        } catch (\Throwable $e) {
            Log::error('Holding site blog article update failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'article_id' => $articleId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.update_error'), 500);
        }
    }

    public function destroy(Request $request, int $articleId): JsonResponse
    {
        try {
            $group = $this->resolveHoldingGroup($request);
            $site = $this->resolveHoldingSite($group);
            $article = BlogArticle::query()
                ->where('blog_context', BlogContextEnum::HOLDING->value)
                ->where('organization_group_id', $group->id)
                ->where('id', $articleId)
                ->firstOrFail();

            if (!$site->canUserEdit(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $this->blogService->deleteArticle($article);

            return LandingResponse::success($this->blogService->listArticles($group, false), trans_message('holding_site_builder.updated'));
        } catch (\Throwable $e) {
            Log::error('Holding site blog article delete failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'article_id' => $articleId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.update_error'), 500);
        }
    }

    private function resolveHoldingGroup(Request $request): OrganizationGroup
    {
        $organizationId = $request->attributes->get('current_organization_id');

        return OrganizationGroup::query()
            ->where('parent_organization_id', $organizationId)
            ->firstOrFail();
    }

    private function resolveHoldingSite(OrganizationGroup $group): HoldingSite
    {
        return HoldingSite::query()
            ->where('organization_group_id', $group->id)
            ->firstOrFail();
    }
}
