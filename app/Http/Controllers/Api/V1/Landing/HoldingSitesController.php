<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\SiteManagementService;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\ContentManagementService;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\AssetManagerService;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteContentBlock;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteAsset;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteTemplate;
use App\Models\OrganizationGroup;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class HoldingSitesController extends Controller
{
    private SiteManagementService $siteService;
    private ContentManagementService $contentService;
    private AssetManagerService $assetService;

    public function __construct(
        SiteManagementService $siteService,
        ContentManagementService $contentService,
        AssetManagerService $assetService
    ) {
        $this->siteService = $siteService;
        $this->contentService = $contentService;
        $this->assetService = $assetService;
    }

    /**
     * Получить все сайты холдинга
     */
    public function index(Request $request, int $holdingId): JsonResponse
    {
        try {
            $organizationGroup = OrganizationGroup::findOrFail($holdingId);
            
            // Проверяем права доступа
            $user = Auth::user();
            if (!$this->canUserManageHoldingSites($user, $organizationGroup)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для управления сайтами холдинга'
                ], 403);
            }

            $sites = $this->siteService->getHoldingSites($organizationGroup);

            return response()->json([
                'success' => true,
                'data' => $sites
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting holding sites', [
                'holding_id' => $holdingId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения списка сайтов'
            ], 500);
        }
    }

    /**
     * Создать новый сайт
     */
    public function store(Request $request, int $holdingId): JsonResponse
    {
        try {
            $organizationGroup = OrganizationGroup::findOrFail($holdingId);
            $user = Auth::user();

            if (!$this->canUserManageHoldingSites($user, $organizationGroup)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для создания сайта'
                ], 403);
            }

            // Валидация данных
            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'template_id' => 'nullable|string|exists:site_templates,template_key',
                'domain' => 'nullable|string|max:255|unique:holding_sites,domain',
                'theme_config' => 'nullable|array',
                'seo_meta' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибки валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $site = $this->siteService->createSite($organizationGroup, $request->all(), $user);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $site->id,
                    'domain' => $site->domain,
                    'title' => $site->title,
                    'status' => $site->status,
                    'template_id' => $site->template_id,
                    'url' => $site->getUrl(),
                    'preview_url' => $site->getPreviewUrl(),
                    'created_at' => $site->created_at
                ],
                'message' => 'Сайт успешно создан'
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating holding site', [
                'holding_id' => $holdingId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания сайта'
            ], 500);
        }
    }

    /**
     * Получить данные сайта
     */
    public function show(Request $request, int $holdingId, int $siteId): JsonResponse
    {
        try {
            $site = HoldingSite::where('id', $siteId)
                ->where('organization_group_id', $holdingId)
                ->firstOrFail();

            $user = Auth::user();
            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для просмотра сайта'
                ], 403);
            }

            $siteData = [
                'id' => $site->id,
                'domain' => $site->domain,
                'title' => $site->title,
                'description' => $site->description,
                'logo_url' => $site->logo_url,
                'favicon_url' => $site->favicon_url,
                'template_id' => $site->template_id,
                'theme_config' => $site->theme_config,
                'seo_meta' => $site->seo_meta,
                'analytics_config' => $site->analytics_config,
                'status' => $site->status,
                'is_active' => $site->is_active,
                'url' => $site->getUrl(),
                'preview_url' => $site->getPreviewUrl(),
                'is_published' => $site->isPublished(),
                'blocks' => $this->contentService->getBlocksForEditing($site),
                'assets_count' => $site->assets()->count(),
                'created_at' => $site->created_at,
                'updated_at' => $site->updated_at
            ];

            return response()->json([
                'success' => true,
                'data' => $siteData
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting holding site', [
                'holding_id' => $holdingId,
                'site_id' => $siteId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения данных сайта'
            ], 500);
        }
    }

    /**
     * Обновить настройки сайта
     */
    public function update(Request $request, int $holdingId, int $siteId): JsonResponse
    {
        try {
            $site = HoldingSite::where('id', $siteId)
                ->where('organization_group_id', $holdingId)
                ->firstOrFail();

            $user = Auth::user();
            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для редактирования сайта'
                ], 403);
            }

            // Валидация данных
            $validator = Validator::make($request->all(), [
                'title' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'theme_config' => 'nullable|array',
                'seo_meta' => 'nullable|array',
                'analytics_config' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибки валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $updated = $this->siteService->updateSiteSettings($site, $request->all(), $user);

            if ($updated) {
                return response()->json([
                    'success' => true,
                    'message' => 'Настройки сайта обновлены'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось обновить настройки'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Error updating holding site', [
                'holding_id' => $holdingId,
                'site_id' => $siteId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления сайта'
            ], 500);
        }
    }

    /**
     * Опубликовать сайт
     */
    public function publish(Request $request, int $holdingId, int $siteId): JsonResponse
    {
        try {
            $site = HoldingSite::where('id', $siteId)
                ->where('organization_group_id', $holdingId)
                ->firstOrFail();

            $user = Auth::user();
            $published = $this->siteService->publishSite($site, $user);

            return response()->json([
                'success' => true,
                'message' => 'Сайт успешно опубликован',
                'data' => [
                    'url' => $site->getUrl(),
                    'published_at' => $site->published_at
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error publishing holding site', [
                'holding_id' => $holdingId,
                'site_id' => $siteId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить сайт
     */
    public function destroy(Request $request, int $holdingId, int $siteId): JsonResponse
    {
        try {
            $site = HoldingSite::where('id', $siteId)
                ->where('organization_group_id', $holdingId)
                ->firstOrFail();

            $user = Auth::user();
            $deleted = $this->siteService->deleteSite($site, $user);

            if ($deleted) {
                return response()->json([
                    'success' => true,
                    'message' => 'Сайт успешно удален'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось удалить сайт'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Error deleting holding site', [
                'holding_id' => $holdingId,
                'site_id' => $siteId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления сайта'
            ], 500);
        }
    }

    /**
     * Проверить может ли пользователь управлять сайтами холдинга
     */
    private function canUserManageHoldingSites($user, OrganizationGroup $organizationGroup): bool
    {
        $parentOrganization = $organizationGroup->parentOrganization;
        
        return $parentOrganization->users()
            ->wherePivot('user_id', $user->id)
            ->wherePivot('is_owner', true)
            ->exists();
    }
}
