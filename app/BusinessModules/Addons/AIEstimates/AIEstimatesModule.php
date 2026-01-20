<?php

namespace App\BusinessModules\Addons\AIEstimates;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class AIEstimatesModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'AI Генерация Смет';
    }

    public function getSlug(): string
    {
        return 'ai-estimates';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Автоматическая генерация строительных смет с помощью искусственного интеллекта YandexGPT';
    }

    public function getType(): ModuleType
    {
        return ModuleType::ADDON;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::SUBSCRIPTION;
    }

    public function getManifest(): array
    {
        return json_decode(file_get_contents(config_path('ModuleList/addons/ai-estimates.json')), true);
    }

    public function install(): void
    {
        // Миграции создадут необходимые таблицы
        // ai_generation_history и ai_generation_feedback
    }

    public function uninstall(): void
    {
        // Платный модуль можно отключить, данные сохраняются
        // Таблицы не удаляются для сохранения истории генераций
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля между версиями
    }

    public function canActivate(int $organizationId): bool
    {
        // Проверяем что необходимые модули активированы
        $accessController = app(\App\Modules\Core\AccessController::class);
        return $accessController->hasModuleAccess($organizationId, 'organizations') &&
               $accessController->hasModuleAccess($organizationId, 'users') &&
               $accessController->hasModuleAccess($organizationId, 'budget-estimates');
    }

    public function getDependencies(): array
    {
        return ['organizations', 'users', 'budget-estimates'];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'ai_estimates.generate',
            'ai_estimates.view_history',
            'ai_estimates.view_generation',
            'ai_estimates.provide_feedback',
            'ai_estimates.export',
            'ai_estimates.clear_cache',
            'ai_estimates.configure',
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Автоматическая генерация смет на основе описания проекта',
            'Умный подбор позиций из каталога EstimatePositionCatalog',
            'Анализ похожих проектов организации для точности расчетов',
            'Обработка загруженных файлов (чертежи, спецификации) с помощью OCR',
            'Распознавание текста через Yandex Vision API',
            'Автоматический расчет объемов и стоимости работ',
            'Кеширование результатов для оптимизации',
            'История всех генераций с возможностью повторного использования',
            'Система обратной связи для обучения AI',
            'Экспорт сгенерированных смет в PDF, Excel, Word',
            'Контроль лимитов использования',
            'Интеграция с YandexGPT 5 Pro',
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_generations_per_month' => 10,
            'max_file_size_mb' => 50,
            'max_files_per_request' => 10,
            'concurrent_generations' => 1,
            'cache_ttl_hours' => 24,
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'ai_settings' => [
                'model' => 'yandexgpt-5-pro',
                'temperature' => 0.3,
                'max_tokens' => 2000,
                'confidence_threshold' => 0.75,
                'use_project_history' => true,
            ],
            'ocr_settings' => [
                'enabled' => true,
                'max_file_size_mb' => 50,
                'allowed_file_types' => ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'xls'],
            ],
            'cache_settings' => [
                'enabled' => true,
                'ttl' => 3600, // 1 час
                'cache_requests_without_files' => true,
            ],
            'catalog_matching' => [
                'fuzzy_search' => true,
                'min_confidence' => 0.6,
                'max_alternatives' => 3,
            ],
            'export_settings' => [
                'default_format' => 'pdf',
                'include_confidence_scores' => true,
                'include_ai_metadata' => false,
            ],
            'notification_settings' => [
                'notify_on_completion' => true,
                'notify_on_error' => true,
            ],
        ];
    }

    public function validateSettings(array $settings): bool
    {
        // Валидация температуры AI
        if (isset($settings['ai_settings']['temperature'])) {
            $temp = $settings['ai_settings']['temperature'];
            if (!is_numeric($temp) || $temp < 0 || $temp > 1) {
                return false;
            }
        }

        // Валидация confidence threshold
        if (isset($settings['ai_settings']['confidence_threshold'])) {
            $threshold = $settings['ai_settings']['confidence_threshold'];
            if (!is_numeric($threshold) || $threshold < 0 || $threshold > 1) {
                return false;
            }
        }

        // Валидация max_tokens
        if (isset($settings['ai_settings']['max_tokens'])) {
            $tokens = $settings['ai_settings']['max_tokens'];
            if (!is_int($tokens) || $tokens < 100 || $tokens > 8000) {
                return false;
            }
        }

        // Валидация размера файла
        if (isset($settings['ocr_settings']['max_file_size_mb'])) {
            $size = $settings['ocr_settings']['max_file_size_mb'];
            if (!is_numeric($size) || $size < 1 || $size > 100) {
                return false;
            }
        }

        // Валидация cache TTL
        if (isset($settings['cache_settings']['ttl'])) {
            $ttl = $settings['cache_settings']['ttl'];
            if (!is_int($ttl) || $ttl < 60 || $ttl > 86400) { // от 1 мин до 24 часов
                return false;
            }
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля AI Генерации Смет');
        }

        $activation = \App\Models\OrganizationModuleActivation::where('organization_id', $organizationId)
            ->whereHas('module', function ($query) {
                $query->where('slug', $this->getSlug());
            })
            ->first();

        if ($activation) {
            $currentSettings = $activation->module_settings ?? [];
            $activation->update([
                'module_settings' => array_merge($currentSettings, $settings)
            ]);
        }
    }

    public function getSettings(int $organizationId): array
    {
        $activation = \App\Models\OrganizationModuleActivation::where('organization_id', $organizationId)
            ->whereHas('module', function ($query) {
                $query->where('slug', $this->getSlug());
            })
            ->first();

        if (!$activation) {
            return $this->getDefaultSettings();
        }

        return array_merge(
            $this->getDefaultSettings(),
            $activation->module_settings ?? []
        );
    }
}
