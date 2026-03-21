<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteAsset;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\AssetManagerService;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\OrganizationGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SiteAssetsController extends Controller
{
    public function __construct(
        private readonly AssetManagerService $assetService
    ) {
    }

    public function indexForHolding(Request $request): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);

            if (!$site->canUserEdit(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            return LandingResponse::success(
                $this->assetService->getSiteAssets($site, $request->query('asset_type'), $request->query('usage_context')),
                trans_message('holding_site_builder.assets.loaded')
            );
        } catch (\Throwable $e) {
            Log::error('Holding site assets load failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.assets.load_error'), 500);
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
                'file' => [
                    'required',
                    'file',
                    'max:' . (AssetManagerService::getMaxFileSize() / 1024),
                    'mimetypes:' . implode(',', AssetManagerService::getAllowedMimeTypes()),
                ],
                'usage_context' => [
                    'required',
                    'string',
                    Rule::in(AssetManagerService::getAllowedUsageContexts()),
                ],
                'metadata' => 'nullable',
            ]);

            if ($validator->fails()) {
                return LandingResponse::error(
                    trans_message('holding_site_builder.validation_error'),
                    422,
                    $validator->errors()
                );
            }

            $metadata = $this->normalizeMetadataInput($request->input('metadata'));
            $asset = $this->assetService->uploadAsset($site, $request->file('file'), (string) $request->input('usage_context'), $user);

            if (!empty($metadata)) {
                $this->assetService->updateAssetMetadata($asset, $metadata);
                $asset = $asset->fresh();
            }

            return LandingResponse::success(
                $this->assetService->serializeAsset($site, $asset),
                trans_message('holding_site_builder.assets.created'),
                201
            );
        } catch (\Throwable $e) {
            Log::error('Holding site asset upload failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.assets.create_error'), 500);
        }
    }

    public function updateForHolding(Request $request, int $assetId): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);
            $asset = SiteAsset::query()
                ->where('id', $assetId)
                ->where('holding_site_id', $site->id)
                ->firstOrFail();

            if (!$site->canUserEdit(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $validator = Validator::make($request->all(), [
                'metadata' => 'required|array',
                'metadata.alt_text' => 'nullable|string|max:255',
                'metadata.caption' => 'nullable|string|max:500',
                'metadata.description' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return LandingResponse::error(
                    trans_message('holding_site_builder.validation_error'),
                    422,
                    $validator->errors()
                );
            }

            $this->assetService->updateAssetMetadata($asset, $validator->validated()['metadata']);

            return LandingResponse::success(
                $this->assetService->serializeAsset($site, $asset->fresh()),
                trans_message('holding_site_builder.assets.updated')
            );
        } catch (\Throwable $e) {
            Log::error('Holding site asset update failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'asset_id' => $assetId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.assets.update_error'), 500);
        }
    }

    public function destroyForHolding(Request $request, int $assetId): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);
            $asset = SiteAsset::query()
                ->where('id', $assetId)
                ->where('holding_site_id', $site->id)
                ->firstOrFail();

            if (!$site->canUserEdit(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $this->assetService->deleteAsset($asset);

            return LandingResponse::success(
                [
                    'deleted_asset_id' => $assetId,
                ],
                trans_message('holding_site_builder.assets.deleted')
            );
        } catch (\RuntimeException $e) {
            return LandingResponse::error(trans_message('holding_site_builder.assets.in_use'), 409);
        } catch (\Throwable $e) {
            Log::error('Holding site asset delete failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'asset_id' => $assetId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.assets.delete_error'), 500);
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

    private function normalizeMetadataInput(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
