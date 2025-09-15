<?php

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Services;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteTemplate;
use App\Models\OrganizationGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Сервис управления сайтами холдингов
 */
class SiteManagementService
{
    private ContentManagementService $contentService;
    private AssetManagerService $assetService;

    public function __construct(
        ContentManagementService $contentService,
        AssetManagerService $assetService
    ) {
        $this->contentService = $contentService;
        $this->assetService = $assetService;
    }

    /**
     * Получить или создать единственный лендинг холдинга
     */
    public function getOrCreateHoldingLanding(OrganizationGroup $organizationGroup, User $creator): HoldingSite
    {
        // Ищем существующий лендинг
        $existingSite = HoldingSite::where('organization_group_id', $organizationGroup->id)->first();
        
        if ($existingSite) {
            return $existingSite;
        }

        // Создаем новый лендинг
        return $this->createHoldingLanding($organizationGroup, [], $creator);
    }

    /**
     * Метод для обратной совместимости (алиас createHoldingLanding)
     */
    public function createSite(OrganizationGroup $organizationGroup, array $data, User $creator): HoldingSite
    {
        return $this->createHoldingLanding($organizationGroup, $data, $creator);
    }

    /**
     * Создать лендинг для холдинга
     */
    public function createHoldingLanding(OrganizationGroup $organizationGroup, array $data, User $creator): HoldingSite
    {
        return DB::transaction(function () use ($organizationGroup, $data, $creator) {
            // Создаем лендинг
            $site = HoldingSite::create([
                'organization_group_id' => $organizationGroup->id,
                'domain' => $data['domain'] ?? ($organizationGroup->slug . '.prohelper.pro'),
                'title' => $data['title'] ?? $organizationGroup->name,
                'description' => $data['description'] ?? "Официальный сайт {$organizationGroup->name}",
                'theme_config' => $data['theme_config'] ?? $this->getDefaultThemeConfig(),
                'seo_meta' => $data['seo_meta'] ?? $this->getDefaultSeoMeta($organizationGroup),
                'status' => 'draft',
                'is_active' => true,
                'created_by_user_id' => $creator->id,
            ]);

            // Создаем базовые блоки по умолчанию
            $this->createDefaultBlocks($site, $creator);

            // Создаем базовые ассеты (если предоставлены)
            if (!empty($data['logo'])) {
                $logoAsset = $this->assetService->uploadAsset($site, $data['logo'], 'logo', $creator);
                $site->update(['logo_url' => $logoAsset->public_url]);
            }

            return $site;
        });
    }

    /**
     * Создать базовые блоки по умолчанию
     */
    private function createDefaultBlocks(HoldingSite $site, User $creator): void
    {
        // Создаем базовый Hero блок
        $this->contentService->createBlock($site, [
            'block_type' => 'hero',
            'title' => 'Главный баннер',
            'content' => [
                'title' => $site->organizationGroup->name,
                'subtitle' => 'Добро пожаловать на наш сайт',
                'description' => $site->description,
                'button_text' => 'Связаться с нами',
                'button_url' => '#contacts',
            ],
            'sort_order' => 1,
            'is_active' => true,
        ], $creator);

        // Создаем блок контактов
        $this->contentService->createBlock($site, [
            'block_type' => 'contacts',
            'title' => 'Контакты',
            'content' => [
                'title' => 'Свяжитесь с нами',
                'phone' => '',
                'email' => '',
                'address' => '',
                'working_hours' => 'Пн-Пт: 9:00-18:00',
            ],
            'sort_order' => 2,
            'is_active' => true,
        ], $creator);
    }

    /**
     * Обновить настройки сайта
     */
    public function updateSiteSettings(HoldingSite $site, array $data, User $user): bool
    {
        $updateData = array_filter([
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'theme_config' => $data['theme_config'] ?? null,
            'seo_meta' => $data['seo_meta'] ?? null,
            'analytics_config' => $data['analytics_config'] ?? null,
            'updated_by_user_id' => $user->id,
        ], fn($value) => $value !== null);

        $updated = $site->update($updateData);
        
        if ($updated) {
            $site->clearCache();
        }

        return $updated;
    }

    /**
     * Опубликовать сайт
     */
    public function publishSite(HoldingSite $site, User $user): bool
    {
        // Проверяем права доступа
        if (!$site->canUserEdit($user)) {
            throw new \Exception('Недостаточно прав для публикации сайта');
        }

        // Валидируем контент
        $validationErrors = $this->validateSiteForPublishing($site);
        if (!empty($validationErrors)) {
            throw new \Exception('Ошибки валидации: ' . implode('; ', $validationErrors));
        }

        return $site->publish($user);
    }

    /**
     * Получить сайт по домену (через slug холдинга)
     */
    public function getSiteByDomain(string $domain): ?HoldingSite
    {
        $cacheKey = "site_by_domain:{$domain}";
        
        return Cache::remember($cacheKey, 300, function () use ($domain) {
            // Извлекаем slug из домена (например, neostroi из neostroi.prohelper.pro)
            $slug = str_replace('.prohelper.pro', '', $domain);
            
            return HoldingSite::whereHas('organizationGroup', function ($query) use ($slug) {
                $query->where('slug', $slug);
            })
                ->where('is_active', true)
                ->with(['organizationGroup.parentOrganization'])
                ->first();
        });
    }

    /**
     * Получить лендинг холдинга
     */
    public function getHoldingLanding(OrganizationGroup $organizationGroup): ?HoldingSite
    {
        return HoldingSite::where('organization_group_id', $organizationGroup->id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Получить данные лендинга холдинга для API
     */
    public function getHoldingLandingData(OrganizationGroup $organizationGroup): ?array
    {
        $site = $this->getHoldingLanding($organizationGroup);
        
        if (!$site) {
            return null;
        }

        return [
            'id' => $site->id,
            'organization_group_id' => $site->organization_group_id,
            'domain' => $site->getDomain(),
            'title' => $site->title,
            'description' => $site->description,
            'logo_url' => $site->logo_url,
            'favicon_url' => $site->favicon_url,
            'theme_config' => $site->theme_config,
            'seo_meta' => $site->seo_meta,
            'analytics_config' => $site->analytics_config,
            'status' => $site->status,
            'url' => $site->getUrl(),
            'preview_url' => $site->getPreviewUrl(),
            'is_active' => $site->is_active,
            'is_published' => $site->isPublished(),
            'published_at' => $site->published_at,
            'created_at' => $site->created_at,
            'updated_at' => $site->updated_at,
            'blocks_count' => $site->contentBlocks()->count(),
            'assets_count' => $site->assets()->count(),
        ];
    }

    /**
     * Удалить сайт
     */
    public function deleteSite(HoldingSite $site, User $user): bool
    {
        if (!$site->canUserEdit($user)) {
            throw new \Exception('Недостаточно прав для удаления сайта');
        }

        return DB::transaction(function () use ($site) {
            // Удаляем все ассеты
            foreach ($site->assets as $asset) {
                $asset->deleteFile();
            }

            // Удаляем блоки контента
            $site->contentBlocks()->delete();

            // Очищаем кэш
            $site->clearCache();

            // Удаляем сам сайт
            return $site->delete();
        });
    }

    /**
     * Валидация сайта перед публикацией
     */
    private function validateSiteForPublishing(HoldingSite $site): array
    {
        $errors = [];

        // Проверяем обязательные поля
        if (empty($site->title)) {
            $errors[] = 'Не указан заголовок сайта';
        }

        // Проверяем наличие обязательных блоков
        $requiredBlocks = ['hero', 'contacts'];
        $existingBlocks = $site->publishedBlocks()->pluck('block_type')->toArray();
        
        foreach ($requiredBlocks as $requiredBlock) {
            if (!in_array($requiredBlock, $existingBlocks)) {
                $errors[] = "Отсутствует обязательный блок: {$requiredBlock}";
            }
        }

        return $errors;
    }

    /**
     * Получить настройки темы по умолчанию
     */
    private function getDefaultThemeConfig(): array
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
            'shadow_style' => 'modern',
        ];
    }

    /**
     * Получить мета-данные по умолчанию
     */
    private function getDefaultSeoMeta(OrganizationGroup $organizationGroup): array
    {
        return [
            'title' => $organizationGroup->name,
            'description' => "Официальный сайт {$organizationGroup->name}",
            'keywords' => '',
            'og_title' => $organizationGroup->name,
            'og_description' => "Официальный сайт {$organizationGroup->name}",
            'og_image' => '',
        ];
    }
}
