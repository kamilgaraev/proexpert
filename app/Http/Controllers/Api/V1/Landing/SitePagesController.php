<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSitePage;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteContentBlock;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\SiteBuilderDataService;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\SitePageService;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\OrganizationGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SitePagesController extends Controller
{
    public function __construct(
        private readonly SitePageService $pageService,
        private readonly SiteBuilderDataService $builderDataService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);

            if (!$site->canUserView(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            return LandingResponse::success($this->builderDataService->getEditorPayload($site)['pages']);
        } catch (\Throwable $e) {
            Log::error('Holding site pages load failed', [
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
            $site = $this->resolveHoldingSite($request);
            $user = Auth::user();

            if (!$site->canUserEdit($user)) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $validator = Validator::make($request->all(), [
                'page_type' => 'required|string|max:64',
                'slug' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('holding_site_pages', 'slug')->where('holding_site_id', $site->id),
                ],
                'navigation_label' => 'nullable|string|max:255',
                'title' => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'seo_meta' => 'nullable|array',
                'layout_config' => 'nullable|array',
                'locale_content' => 'nullable|array',
                'visibility' => 'nullable|string|max:32',
                'sort_order' => 'nullable|integer|min:1',
                'is_home' => 'nullable|boolean',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return LandingResponse::error(trans_message('holding_site_builder.validation_error'), 422, $validator->errors());
            }

            $page = $this->pageService->createPage($site, $validator->validated(), $user);

            return LandingResponse::success($this->serializePage($site->fresh(), $page->id), trans_message('holding_site_builder.updated'), 201);
        } catch (\Throwable $e) {
            Log::error('Holding site page create failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.update_error'), 500);
        }
    }

    public function update(Request $request, int $pageId): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);
            $page = $this->resolvePage($site, $pageId);
            $user = Auth::user();

            if (!$site->canUserEdit($user)) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $validator = Validator::make($request->all(), [
                'page_type' => 'nullable|string|max:64',
                'slug' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('holding_site_pages', 'slug')->where('holding_site_id', $site->id)->ignore($page->id),
                ],
                'navigation_label' => 'nullable|string|max:255',
                'title' => 'nullable|string|max:255',
                'description' => 'nullable|string|max:1000',
                'seo_meta' => 'nullable|array',
                'layout_config' => 'nullable|array',
                'locale_content' => 'nullable|array',
                'visibility' => 'nullable|string|max:32',
                'sort_order' => 'nullable|integer|min:1',
                'is_home' => 'nullable|boolean',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return LandingResponse::error(trans_message('holding_site_builder.validation_error'), 422, $validator->errors());
            }

            $updatedPage = $this->pageService->updatePage($page, $validator->validated(), $user);

            return LandingResponse::success($this->serializePage($site->fresh(), $updatedPage->id), trans_message('holding_site_builder.updated'));
        } catch (\Throwable $e) {
            Log::error('Holding site page update failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'page_id' => $pageId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.update_error'), 500);
        }
    }

    public function destroy(Request $request, int $pageId): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);
            $page = $this->resolvePage($site, $pageId);

            if (!$site->canUserEdit(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $this->pageService->deletePage($page);

            return LandingResponse::success($this->builderDataService->getEditorPayload($site->fresh())['pages'], trans_message('holding_site_builder.updated'));
        } catch (\InvalidArgumentException $e) {
            return LandingResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('Holding site page delete failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'page_id' => $pageId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.update_error'), 500);
        }
    }

    public function reorder(Request $request): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);

            if (!$site->canUserEdit(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $validator = Validator::make($request->all(), [
                'page_order' => 'required|array',
                'page_order.*' => [
                    'integer',
                    Rule::exists('holding_site_pages', 'id')->where('holding_site_id', $site->id),
                ],
            ]);

            if ($validator->fails()) {
                return LandingResponse::error(trans_message('holding_site_builder.validation_error'), 422, $validator->errors());
            }

            $this->pageService->reorderPages($site, $validator->validated()['page_order'], Auth::user());

            return LandingResponse::success($this->builderDataService->getEditorPayload($site->fresh())['pages'], trans_message('holding_site_builder.updated'));
        } catch (\Throwable $e) {
            Log::error('Holding site page reorder failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.update_error'), 500);
        }
    }

    public function storeSection(Request $request, int $pageId): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);
            $page = $this->resolvePage($site, $pageId);

            if (!$site->canUserEdit(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $validator = Validator::make($request->all(), [
                'block_type' => ['required', 'string', Rule::in(array_keys(SiteContentBlock::BLOCK_TYPES))],
                'title' => 'nullable|string|max:255',
                'content' => 'nullable|array',
                'settings' => 'nullable|array',
                'bindings' => 'nullable|array',
                'locale_content' => 'nullable|array',
                'style_config' => 'nullable|array',
                'sort_order' => 'nullable|integer|min:1',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return LandingResponse::error(trans_message('holding_site_builder.validation_error'), 422, $validator->errors());
            }

            $section = $this->pageService->createSection($page, $validator->validated(), Auth::user());

            return LandingResponse::success($this->serializeSection($site->fresh(), $section->id), trans_message('holding_site_builder.blocks.created'), 201);
        } catch (\Throwable $e) {
            Log::error('Holding site section create failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'page_id' => $pageId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.blocks.create_error'), 500);
        }
    }

    public function updateSection(Request $request, int $pageId, int $sectionId): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);
            $page = $this->resolvePage($site, $pageId);
            $section = $this->resolveSection($page, $sectionId);

            if (!$site->canUserEdit(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $validator = Validator::make($request->all(), [
                'title' => 'nullable|string|max:255',
                'content' => 'nullable|array',
                'settings' => 'nullable|array',
                'bindings' => 'nullable|array',
                'locale_content' => 'nullable|array',
                'style_config' => 'nullable|array',
                'sort_order' => 'nullable|integer|min:1',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return LandingResponse::error(trans_message('holding_site_builder.validation_error'), 422, $validator->errors());
            }

            $updated = $this->pageService->updateSection($section, $validator->validated(), Auth::user());

            return LandingResponse::success($this->serializeSection($site->fresh(), $updated->id), trans_message('holding_site_builder.blocks.updated'));
        } catch (\Throwable $e) {
            Log::error('Holding site section update failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'page_id' => $pageId,
                'section_id' => $sectionId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.blocks.update_error'), 500);
        }
    }

    public function destroySection(Request $request, int $pageId, int $sectionId): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);
            $page = $this->resolvePage($site, $pageId);
            $section = $this->resolveSection($page, $sectionId);

            if (!$site->canUserEdit(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $this->pageService->deleteSection($section);

            return LandingResponse::success($this->serializePage($site->fresh(), $page->id), trans_message('holding_site_builder.blocks.deleted'));
        } catch (\Throwable $e) {
            Log::error('Holding site section delete failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'page_id' => $pageId,
                'section_id' => $sectionId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.blocks.delete_error'), 500);
        }
    }

    public function duplicateSection(Request $request, int $sectionId): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);
            $section = SiteContentBlock::query()
                ->where('holding_site_id', $site->id)
                ->where('id', $sectionId)
                ->firstOrFail();

            if (!$site->canUserEdit(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $duplicate = $this->pageService->duplicateSection($section, Auth::user());

            return LandingResponse::success($this->serializeSection($site->fresh(), $duplicate->id), trans_message('holding_site_builder.blocks.duplicated'), 201);
        } catch (\Throwable $e) {
            Log::error('Holding site section duplicate failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'section_id' => $sectionId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.blocks.duplicate_error'), 500);
        }
    }

    public function reorderSections(Request $request, int $pageId): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);
            $page = $this->resolvePage($site, $pageId);

            if (!$site->canUserEdit(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $validator = Validator::make($request->all(), [
                'section_order' => 'required|array',
                'section_order.*' => [
                    'integer',
                    Rule::exists('site_content_blocks', 'id')->where('holding_site_page_id', $page->id),
                ],
            ]);

            if ($validator->fails()) {
                return LandingResponse::error(trans_message('holding_site_builder.validation_error'), 422, $validator->errors());
            }

            $this->pageService->reorderSections($page, $validator->validated()['section_order'], Auth::user());

            return LandingResponse::success($this->serializePage($site->fresh(), $page->id), trans_message('holding_site_builder.blocks.reordered'));
        } catch (\Throwable $e) {
            Log::error('Holding site section reorder failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'page_id' => $pageId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.blocks.reorder_error'), 500);
        }
    }

    private function resolveHoldingSite(Request $request): HoldingSite
    {
        $organizationId = $request->attributes->get('current_organization_id');
        $organizationGroup = OrganizationGroup::query()
            ->where('parent_organization_id', $organizationId)
            ->firstOrFail();

        return HoldingSite::query()
            ->where('organization_group_id', $organizationGroup->id)
            ->firstOrFail();
    }

    private function resolvePage(HoldingSite $site, int $pageId): HoldingSitePage
    {
        return HoldingSitePage::query()
            ->where('holding_site_id', $site->id)
            ->where('id', $pageId)
            ->firstOrFail();
    }

    private function resolveSection(HoldingSitePage $page, int $sectionId): SiteContentBlock
    {
        return SiteContentBlock::query()
            ->where('holding_site_page_id', $page->id)
            ->where('id', $sectionId)
            ->firstOrFail();
    }

    private function serializePage(HoldingSite $site, int $pageId): array
    {
        return collect($this->builderDataService->getEditorPayload($site)['pages'])
            ->firstWhere('id', $pageId) ?? [];
    }

    private function serializeSection(HoldingSite $site, int $sectionId): array
    {
        return collect($this->builderDataService->getEditorPayload($site)['pages'])
            ->flatMap(fn (array $page) => $page['sections'] ?? [])
            ->firstWhere('id', $sectionId) ?? [];
    }
}
