<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteContentBlock;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\ContentManagementService;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\SiteBuilderDataService;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\OrganizationGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SiteBlocksController extends Controller
{
    public function __construct(
        private readonly ContentManagementService $contentService,
        private readonly SiteBuilderDataService $builderDataService
    ) {
    }

    public function indexForHolding(Request $request): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);
            $homePage = $site->homePage();

            if (!$site->canUserEdit(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            return LandingResponse::success(
                collect($this->builderDataService->getEditorPayload($site)['pages'])
                    ->firstWhere('id', $homePage?->id)['sections'] ?? [],
                trans_message('holding_site_builder.blocks.loaded')
            );
        } catch (\Throwable $e) {
            Log::error('Holding site blocks load failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.blocks.load_error'), 500);
        }
    }

    public function storeForHolding(Request $request): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);
            $user = Auth::user();

            if (!$site->canUserEdit($user)) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $validator = Validator::make($request->all(), [
                'block_type' => [
                    'required',
                    'string',
                    Rule::in(array_keys(SiteContentBlock::BLOCK_TYPES)),
                ],
                'title' => 'nullable|string|max:255',
                'content' => 'nullable|array',
                'settings' => 'nullable|array',
                'bindings' => 'nullable|array',
                'sort_order' => 'nullable|integer|min:1',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return LandingResponse::error(
                    trans_message('holding_site_builder.validation_error'),
                    422,
                    $validator->errors()
                );
            }

            $data = $validator->validated();
            $contentErrors = $this->contentService->validateBlockData(
                $data['block_type'],
                $data['content'] ?? []
            );

            if (!empty($contentErrors)) {
                return LandingResponse::error(trans_message('holding_site_builder.validation_error'), 422, $contentErrors);
            }

            $block = $this->contentService->createBlock($site, $data, $user);

            return LandingResponse::success(
                $this->serializeBlock($site->fresh(), $block->id),
                trans_message('holding_site_builder.blocks.created'),
                201
            );
        } catch (\Throwable $e) {
            Log::error('Holding site block create failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.blocks.create_error'), 500);
        }
    }

    public function updateForHolding(Request $request, int $blockId): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);
            $homePage = $site->homePage();
            $block = SiteContentBlock::query()
                ->where('id', $blockId)
                ->where('holding_site_id', $site->id)
                ->where('holding_site_page_id', $homePage?->id)
                ->firstOrFail();

            if (!$site->canUserEdit(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $validator = Validator::make($request->all(), [
                'title' => 'nullable|string|max:255',
                'content' => 'sometimes|required|array',
                'settings' => 'nullable|array',
                'bindings' => 'nullable|array',
                'sort_order' => 'nullable|integer|min:1',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return LandingResponse::error(
                    trans_message('holding_site_builder.validation_error'),
                    422,
                    $validator->errors()
                );
            }

            $data = $validator->validated();
            if (array_key_exists('content', $data)) {
                $contentErrors = $this->contentService->validateBlockData($block->block_type, $data['content']);

                if (!empty($contentErrors)) {
                    return LandingResponse::error(trans_message('holding_site_builder.validation_error'), 422, $contentErrors);
                }
            }

            $this->contentService->updateBlock($block, $data, Auth::user());

            return LandingResponse::success(
                $this->serializeBlock($site->fresh(), $block->id),
                trans_message('holding_site_builder.blocks.updated')
            );
        } catch (\Throwable $e) {
            Log::error('Holding site block update failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'block_id' => $blockId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.blocks.update_error'), 500);
        }
    }

    public function publishForHolding(Request $request, int $blockId): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);
            $homePage = $site->homePage();
            $block = SiteContentBlock::query()
                ->where('id', $blockId)
                ->where('holding_site_id', $site->id)
                ->where('holding_site_page_id', $homePage?->id)
                ->firstOrFail();

            $this->contentService->publishBlock($block, Auth::user());

            return LandingResponse::success(
                $this->serializeBlock($site->fresh(), $block->id),
                trans_message('holding_site_builder.blocks.published')
            );
        } catch (\Throwable $e) {
            Log::error('Holding site block publish failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'block_id' => $blockId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error($e->getMessage() ?: trans_message('holding_site_builder.blocks.publish_error'), 500);
        }
    }

    public function duplicateForHolding(Request $request, int $blockId): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);
            $homePage = $site->homePage();
            $block = SiteContentBlock::query()
                ->where('id', $blockId)
                ->where('holding_site_id', $site->id)
                ->where('holding_site_page_id', $homePage?->id)
                ->firstOrFail();

            if (!$site->canUserEdit(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $newBlock = $this->contentService->duplicateBlock($block, Auth::user());

            return LandingResponse::success(
                $this->serializeBlock($site->fresh(), $newBlock->id),
                trans_message('holding_site_builder.blocks.duplicated'),
                201
            );
        } catch (\Throwable $e) {
            Log::error('Holding site block duplicate failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'block_id' => $blockId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.blocks.duplicate_error'), 500);
        }
    }

    public function destroyForHolding(Request $request, int $blockId): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);
            $homePage = $site->homePage();
            $block = SiteContentBlock::query()
                ->where('id', $blockId)
                ->where('holding_site_id', $site->id)
                ->where('holding_site_page_id', $homePage?->id)
                ->firstOrFail();

            if (!$site->canUserEdit(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $this->contentService->deleteBlock($block);

            return LandingResponse::success(
                [
                    'deleted_block_id' => $blockId,
                    'blocks' => $this->builderDataService->getEditorPayload($site->fresh())['blocks'],
                ],
                trans_message('holding_site_builder.blocks.deleted')
            );
        } catch (\Throwable $e) {
            Log::error('Holding site block delete failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'block_id' => $blockId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.blocks.delete_error'), 500);
        }
    }

    public function reorderForHolding(Request $request): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);
            $homePage = $site->homePage();

            if (!$site->canUserEdit(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $validator = Validator::make($request->all(), [
                'block_order' => 'required|array',
                'block_order.*' => [
                    'integer',
                    Rule::exists('site_content_blocks', 'id')
                        ->where('holding_site_id', $site->id)
                        ->where('holding_site_page_id', $homePage?->id),
                ],
            ]);

            if ($validator->fails()) {
                return LandingResponse::error(
                    trans_message('holding_site_builder.validation_error'),
                    422,
                    $validator->errors()
                );
            }

            $this->contentService->reorderPageSections($homePage, $validator->validated()['block_order'], Auth::user());

            return LandingResponse::success(
                $this->builderDataService->getEditorPayload($site->fresh())['blocks'],
                trans_message('holding_site_builder.blocks.reordered')
            );
        } catch (\Throwable $e) {
            Log::error('Holding site block reorder failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
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

    private function serializeBlock(HoldingSite $site, int $blockId): array
    {
        return collect($this->builderDataService->getEditorPayload($site)['blocks'])
            ->firstWhere('id', $blockId) ?? [];
    }
}
