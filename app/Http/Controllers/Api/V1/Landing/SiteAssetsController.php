<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\AssetManagerService;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteAsset;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SiteAssetsController extends Controller
{
    private AssetManagerService $assetService;

    public function __construct(AssetManagerService $assetService)
    {
        $this->assetService = $assetService;
    }

    /**
     * Получить все файлы сайта
     */
    public function index(Request $request, int $holdingId, int $siteId): JsonResponse
    {
        try {
            $site = HoldingSite::where('id', $siteId)
                ->where('organization_group_id', $holdingId)
                ->firstOrFail();

            $user = Auth::user();
            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для просмотра файлов'
                ], 403);
            }

            $assetType = $request->query('asset_type');
            $usageContext = $request->query('usage_context');

            $assets = $this->assetService->getSiteAssets($site, $assetType, $usageContext);

            return response()->json([
                'success' => true,
                'data' => $assets
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting site assets', [
                'site_id' => $siteId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения файлов'
            ], 500);
        }
    }

    /**
     * Загрузить файл
     */
    public function store(Request $request, int $holdingId, int $siteId): JsonResponse
    {
        try {
            $site = HoldingSite::where('id', $siteId)
                ->where('organization_group_id', $holdingId)
                ->firstOrFail();

            $user = Auth::user();
            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для загрузки файлов'
                ], 403);
            }

            // Валидация данных
            $validator = Validator::make($request->all(), [
                'file' => [
                    'required',
                    'file',
                    'max:' . (AssetManagerService::getMaxFileSize() / 1024), // В килобайтах
                    'mimes:' . implode(',', array_map(
                        fn($mime) => last(explode('/', $mime)),
                        AssetManagerService::getAllowedMimeTypes()
                    ))
                ],
                'usage_context' => 'required|string|in:hero,logo,gallery,about,team,projects,favicon,general',
                'metadata' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибки валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $file = $request->file('file');
            $usageContext = $request->input('usage_context', 'general');

            $asset = $this->assetService->uploadAsset($site, $file, $usageContext, $user);

            // Обновляем метаданные если переданы
            if ($request->has('metadata')) {
                $this->assetService->updateAssetMetadata($asset, $request->metadata);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $asset->id,
                    'filename' => $asset->filename,
                    'public_url' => $asset->public_url,
                    'optimized_url' => $asset->getOptimizedUrl(),
                    'mime_type' => $asset->mime_type,
                    'file_size' => $asset->file_size,
                    'human_size' => $asset->getHumanReadableSize(),
                    'asset_type' => $asset->asset_type,
                    'usage_context' => $asset->usage_context,
                    'metadata' => $asset->metadata,
                    'is_optimized' => $asset->is_optimized,
                    'uploaded_at' => $asset->created_at
                ],
                'message' => 'Файл успешно загружен'
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error uploading site asset', [
                'site_id' => $siteId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки файла'
            ], 500);
        }
    }

    /**
     * Обновить метаданные файла
     */
    public function update(Request $request, int $holdingId, int $siteId, int $assetId): JsonResponse
    {
        try {
            $asset = SiteAsset::where('id', $assetId)
                ->where('holding_site_id', $siteId)
                ->firstOrFail();

            $site = $asset->holdingSite;
            $user = Auth::user();

            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для редактирования файла'
                ], 403);
            }

            // Валидация данных
            $validator = Validator::make($request->all(), [
                'metadata' => 'required|array',
                'metadata.alt_text' => 'nullable|string|max:255',
                'metadata.caption' => 'nullable|string|max:500',
                'metadata.description' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибки валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $updated = $this->assetService->updateAssetMetadata($asset, $request->metadata);

            if ($updated) {
                return response()->json([
                    'success' => true,
                    'message' => 'Метаданные файла обновлены'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось обновить метаданные'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Error updating site asset', [
                'asset_id' => $assetId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления файла'
            ], 500);
        }
    }

    // === МЕТОДЫ ДЛЯ РАБОТЫ С ОДНИМ ЛЕНДИНГОМ НА ХОЛДИНГ ===

    /**
     * Получить все файлы лендинга холдинга
     */
    public function indexForHolding(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $organizationGroup = \App\Models\OrganizationGroup::where('parent_organization_id', $organizationId)->firstOrFail();
            
            $site = $this->getHoldingLanding($organizationGroup->id);
            $user = Auth::user();

            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для просмотра файлов'
                ], 403);
            }

            $assetType = $request->query('asset_type');
            $usageContext = $request->query('usage_context');

            $assets = $this->assetService->getSiteAssets($site, $assetType, $usageContext);

            return response()->json([
                'success' => true,
                'data' => $assets
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting holding landing assets', [
                'organization_id' => $organizationId ?? null,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения файлов лендинга'
            ], 500);
        }
    }

    /**
     * Загрузить файл для лендинга холдинга
     */
    public function storeForHolding(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $organizationGroup = \App\Models\OrganizationGroup::where('parent_organization_id', $organizationId)->firstOrFail();
            
            $site = $this->getHoldingLanding($organizationGroup->id);
            $user = Auth::user();

            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для загрузки файлов'
                ], 403);
            }

            return $this->uploadAssetForSite($site, $request, $user);

        } catch (\Exception $e) {
            Log::error('Error uploading holding landing asset', [
                'organization_id' => $organizationId ?? null,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки файла в лендинг'
            ], 500);
        }
    }

    /**
     * Обновить метаданные файла лендинга холдинга
     */
    public function updateForHolding(Request $request, int $assetId): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $organizationGroup = \App\Models\OrganizationGroup::where('parent_organization_id', $organizationId)->firstOrFail();
            
            $site = $this->getHoldingLanding($organizationGroup->id);
            $asset = SiteAsset::where('id', $assetId)
                ->where('holding_site_id', $site->id)
                ->firstOrFail();

            $user = Auth::user();
            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для редактирования файла'
                ], 403);
            }

            return $this->updateAssetMetadata($asset, $request);

        } catch (\Exception $e) {
            Log::error('Error updating holding landing asset', [
                'organization_id' => $organizationId ?? null,
                'asset_id' => $assetId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления файла лендинга'
            ], 500);
        }
    }

    /**
     * Удалить файл лендинга холдинга
     */
    public function destroyForHolding(Request $request, int $assetId): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $organizationGroup = \App\Models\OrganizationGroup::where('parent_organization_id', $organizationId)->firstOrFail();
            
            $site = $this->getHoldingLanding($organizationGroup->id);
            $asset = SiteAsset::where('id', $assetId)
                ->where('holding_site_id', $site->id)
                ->firstOrFail();

            $user = Auth::user();
            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для удаления файла'
                ], 403);
            }

            $deleted = $this->assetService->deleteAsset($asset);

            if ($deleted) {
                return response()->json([
                    'success' => true,
                    'message' => 'Файл удален'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось удалить файл'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Error deleting holding landing asset', [
                'organization_id' => $organizationId ?? null,
                'asset_id' => $assetId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления файла лендинга'
            ], 500);
        }
    }

    // === ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ ===

    /**
     * Получить лендинг холдинга
     */
    private function getHoldingLanding(int $holdingId): HoldingSite
    {
        return HoldingSite::where('organization_group_id', $holdingId)->firstOrFail();
    }

    /**
     * Загрузить ассет для сайта (переиспользуемый метод)
     */
    private function uploadAssetForSite(HoldingSite $site, Request $request, $user): JsonResponse
    {
        // Валидация данных
        $validator = Validator::make($request->all(), [
            'file' => [
                'required',
                'file',
                'max:' . (AssetManagerService::getMaxFileSize() / 1024), // В килобайтах
                'mimes:' . implode(',', array_map(
                    fn($mime) => last(explode('/', $mime)),
                    AssetManagerService::getAllowedMimeTypes()
                ))
            ],
            'usage_context' => 'required|string|in:hero,logo,gallery,about,team,projects,favicon,general',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибки валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $file = $request->file('file');
        $usageContext = $request->input('usage_context', 'general');

        $asset = $this->assetService->uploadAsset($site, $file, $usageContext, $user);

        // Обновляем метаданные если переданы
        if ($request->has('metadata')) {
            $this->assetService->updateAssetMetadata($asset, $request->metadata);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $asset->id,
                'filename' => $asset->filename,
                'public_url' => $asset->public_url,
                'optimized_url' => $asset->getOptimizedUrl(),
                'mime_type' => $asset->mime_type,
                'file_size' => $asset->file_size,
                'human_size' => $asset->getHumanReadableSize(),
                'asset_type' => $asset->asset_type,
                'usage_context' => $asset->usage_context,
                'metadata' => $asset->metadata,
                'is_optimized' => $asset->is_optimized,
                'uploaded_at' => $asset->created_at
            ],
            'message' => 'Файл успешно загружен в лендинг'
        ], 201);
    }

    /**
     * Обновить метаданные ассета (переиспользуемый метод)
     */
    private function updateAssetMetadata(SiteAsset $asset, Request $request): JsonResponse
    {
        // Валидация данных
        $validator = Validator::make($request->all(), [
            'metadata' => 'required|array',
            'metadata.alt_text' => 'nullable|string|max:255',
            'metadata.caption' => 'nullable|string|max:500',
            'metadata.description' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибки валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $updated = $this->assetService->updateAssetMetadata($asset, $request->metadata);

        if ($updated) {
            return response()->json([
                'success' => true,
                'message' => 'Метаданные файла обновлены'
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Не удалось обновить метаданные'
            ], 500);
        }
    }

    /**
     * Удалить файл
     */
    public function destroy(Request $request, int $holdingId, int $siteId, int $assetId): JsonResponse
    {
        try {
            $asset = SiteAsset::where('id', $assetId)
                ->where('holding_site_id', $siteId)
                ->firstOrFail();

            $site = $asset->holdingSite;
            $user = Auth::user();

            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для удаления файла'
                ], 403);
            }

            $deleted = $this->assetService->deleteAsset($asset);

            if ($deleted) {
                return response()->json([
                    'success' => true,
                    'message' => 'Файл удален'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось удалить файл'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Error deleting site asset', [
                'asset_id' => $assetId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления файла'
            ], 500);
        }
    }
}
