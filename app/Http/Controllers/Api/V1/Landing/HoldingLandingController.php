<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\SiteManagementService;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\ContentManagementService;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\Models\OrganizationGroup;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Контроллер для управления лендингом холдинга (упрощенная Тильда)
 * Один лендинг на холдинг с блочной системой
 */
class HoldingLandingController extends Controller
{
    private SiteManagementService $siteService;
    private ContentManagementService $contentService;

    public function __construct(
        SiteManagementService $siteService,
        ContentManagementService $contentService
    ) {
        $this->siteService = $siteService;
        $this->contentService = $contentService;
    }

    /**
     * Получить лендинг холдинга (создать если не существует)
     */
    public function show(Request $request, int $holdingId): JsonResponse
    {
        try {
            $organizationGroup = OrganizationGroup::findOrFail($holdingId);
            $user = Auth::user();

            if (!$this->canUserEditLanding($user, $organizationGroup)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для просмотра лендинга'
                ], 403);
            }

            // Ищем существующий лендинг или создаем новый
            $site = HoldingSite::where('organization_group_id', $holdingId)->first();
            
            if (!$site) {
                // Автоматически создаем лендинг для холдинга
                $site = $this->siteService->createSite($organizationGroup, [
                    'title' => $organizationGroup->name,
                    'domain' => $organizationGroup->slug . '.prohelper.pro',
                    'description' => "Официальный сайт {$organizationGroup->name}"
                ], $user);
            }

            $landingData = [
                'id' => $site->id,
                'domain' => $site->domain,
                'title' => $site->title,
                'description' => $site->description,
                'logo_url' => $site->logo_url,
                'favicon_url' => $site->favicon_url,
                'theme_config' => $site->theme_config ?? $this->getDefaultTheme(),
                'seo_meta' => $site->seo_meta ?? $this->getDefaultSeoMeta($organizationGroup),
                'analytics_config' => $site->analytics_config,
                'status' => $site->status,
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
                'data' => $landingData
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting holding landing', [
                'holding_id' => $holdingId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения лендинга'
            ], 500);
        }
    }

    /**
     * Обновить настройки лендинга
     */
    public function update(Request $request, int $holdingId): JsonResponse
    {
        try {
            $site = HoldingSite::where('organization_group_id', $holdingId)->firstOrFail();
            $user = Auth::user();

            if (!$this->canUserEditLanding($user, $site->organizationGroup)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для редактирования лендинга'
                ], 403);
            }

            // Валидация данных
            $validator = Validator::make($request->all(), [
                'title' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'theme_config' => 'nullable|array',
                'theme_config.primary_color' => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
                'theme_config.secondary_color' => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
                'theme_config.font_family' => 'nullable|string|max:100',
                'seo_meta' => 'nullable|array',
                'seo_meta.title' => 'nullable|string|max:60',
                'seo_meta.description' => 'nullable|string|max:160',
                'seo_meta.keywords' => 'nullable|string|max:255',
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
                    'message' => 'Настройки лендинга обновлены'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось обновить настройки'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Error updating holding landing', [
                'holding_id' => $holdingId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления лендинга'
            ], 500);
        }
    }

    /**
     * Опубликовать лендинг
     */
    public function publish(Request $request, int $holdingId): JsonResponse
    {
        try {
            $site = HoldingSite::where('organization_group_id', $holdingId)->firstOrFail();
            $user = Auth::user();

            $published = $this->siteService->publishSite($site, $user);

            return response()->json([
                'success' => true,
                'message' => 'Лендинг успешно опубликован',
                'data' => [
                    'url' => $site->getUrl(),
                    'published_at' => $site->published_at
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error publishing holding landing', [
                'holding_id' => $holdingId,
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
     * Проверить может ли пользователь редактировать лендинг
     */
    private function canUserEditLanding($user, OrganizationGroup $organizationGroup): bool
    {
        $parentOrganization = $organizationGroup->parentOrganization;
        
        return $parentOrganization->users()
            ->wherePivot('user_id', $user->id)
            ->wherePivot('is_owner', true)
            ->exists();
    }

    /**
     * Получить тему по умолчанию (как в Тильде)
     */
    private function getDefaultTheme(): array
    {
        return [
            'primary_color' => '#2563eb',
            'secondary_color' => '#64748b',
            'accent_color' => '#f59e0b',
            'background_color' => '#ffffff',
            'text_color' => '#1f2937',
            'font_family' => 'Inter, sans-serif',
            'font_size_base' => '16px',
            'border_radius' => '8px',
            'shadow_style' => 'modern'
        ];
    }

    /**
     * Получить SEO по умолчанию
     */
    private function getDefaultSeoMeta(OrganizationGroup $organizationGroup): array
    {
        return [
            'title' => $organizationGroup->name,
            'description' => "Официальный сайт {$organizationGroup->name}",
            'keywords' => $organizationGroup->name,
            'og_title' => $organizationGroup->name,
            'og_description' => "Официальный сайт {$organizationGroup->name}",
            'og_image' => ''
        ];
    }
}
