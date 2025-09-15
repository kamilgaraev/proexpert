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
     * Создать новый сайт для холдинга
     */
    public function createSite(OrganizationGroup $organizationGroup, array $data, User $creator): HoldingSite
    {
        return DB::transaction(function () use ($organizationGroup, $data, $creator) {
            // Генерируем домен на основе slug холдинга
            $domain = $data['domain'] ?? $organizationGroup->slug . '.prohelper.pro';
            
            // Создаем основной сайт
            $site = HoldingSite::create([
                'organization_group_id' => $organizationGroup->id,
                'domain' => $domain,
                'title' => $data['title'] ?? $organizationGroup->name,
                'description' => $data['description'] ?? "Официальный сайт {$organizationGroup->name}",
                'template_id' => $data['template_id'] ?? 'default',
                'theme_config' => $data['theme_config'] ?? [],
                'seo_meta' => $data['seo_meta'] ?? $this->getDefaultSeoMeta($organizationGroup),
                'status' => 'draft',
                'is_active' => true,
                'created_by_user_id' => $creator->id,
            ]);

            // Применяем шаблон
            $this->applyTemplate($site, $data['template_id'] ?? 'default', $creator);

            // Создаем базовые ассеты (если предоставлены)
            if (!empty($data['logo'])) {
                $logoAsset = $this->assetService->uploadAsset($site, $data['logo'], 'logo', $creator);
                $site->update(['logo_url' => $logoAsset->public_url]);
            }

            return $site;
        });
    }

    /**
     * Применить шаблон к сайту
     */
    public function applyTemplate(HoldingSite $site, string $templateId, User $user): bool
    {
        $template = SiteTemplate::where('template_key', $templateId)
            ->where('is_active', true)
            ->first();

        if (!$template) {
            throw new \Exception("Шаблон {$templateId} не найден");
        }

        // Удаляем существующие блоки (если есть)
        $site->contentBlocks()->delete();

        // Создаем блоки из шаблона
        $template->createSiteFromTemplate($site, $user);

        // Обновляем настройки темы
        $site->update([
            'template_id' => $templateId,
            'theme_config' => array_merge(
                $template->getAvailableThemeOptions(),
                $site->theme_config ?? []
            ),
            'updated_by_user_id' => $user->id,
        ]);

        $site->clearCache();

        return true;
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
     * Получить сайт по домену
     */
    public function getSiteByDomain(string $domain): ?HoldingSite
    {
        $cacheKey = "site_by_domain:{$domain}";
        
        return Cache::remember($cacheKey, 300, function () use ($domain) {
            return HoldingSite::where('domain', $domain)
                ->where('is_active', true)
                ->with(['organizationGroup.parentOrganization'])
                ->first();
        });
    }

    /**
     * Получить все сайты холдинга
     */
    public function getHoldingSites(OrganizationGroup $organizationGroup): array
    {
        $sites = HoldingSite::where('organization_group_id', $organizationGroup->id)
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->get();

        return $sites->map(function ($site) {
            return [
                'id' => $site->id,
                'domain' => $site->domain,
                'title' => $site->title,
                'status' => $site->status,
                'template_id' => $site->template_id,
                'url' => $site->getUrl(),
                'preview_url' => $site->getPreviewUrl(),
                'is_published' => $site->isPublished(),
                'last_updated' => $site->updated_at,
                'blocks_count' => $site->contentBlocks()->count(),
                'assets_count' => $site->assets()->count(),
            ];
        })->toArray();
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
