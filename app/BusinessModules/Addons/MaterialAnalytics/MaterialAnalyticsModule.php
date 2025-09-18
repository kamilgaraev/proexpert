<?php

namespace App\BusinessModules\Addons\MaterialAnalytics;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class MaterialAnalyticsModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Аналитика материалов';
    }

    public function getSlug(): string
    {
        return 'material-analytics';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Расширенная аналитика и отчеты по материалам';
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
        return json_decode(file_get_contents(config_path('ModuleList/addons/material-analytics.json')), true);
    }

    public function install(): void
    {
        // Модуль использует существующие таблицы материалов и аналитики
    }

    public function uninstall(): void
    {
        // Платный модуль можно отключить, данные сохраняются
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля
    }

    public function canActivate(int $organizationId): bool
    {
        // Проверяем что необходимые модули активированы
        $accessController = app(\App\Modules\Core\AccessController::class);
        return $accessController->hasModuleAccess($organizationId, 'organizations') &&
               $accessController->hasModuleAccess($organizationId, 'users') &&
               $accessController->hasModuleAccess($organizationId, 'catalog-management') &&
               $accessController->hasModuleAccess($organizationId, 'project-management');
    }

    public function getDependencies(): array
    {
        return ['organizations', 'users', 'catalog-management', 'project-management'];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'materials.analytics.summary',
            'materials.analytics.usage_by_projects',
            'materials.analytics.usage_by_suppliers',
            'materials.analytics.low_stock',
            'materials.analytics.most_used',
            'materials.analytics.cost_history',
            'materials.analytics.movement_report',
            'materials.analytics.inventory_report',
            'materials.analytics.cost_dynamics_report'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Сводная аналитика по материалам',
            'Анализ использования по проектам',
            'Анализ использования по поставщикам',
            'Отчет по материалам с низким остатком',
            'Топ наиболее используемых материалов',
            'История изменения стоимости материалов',
            'Отчет по движению материалов',
            'Инвентарные отчеты',
            'Отчет по динамике стоимости',
            'Прогнозирование потребностей',
            'Визуализация данных в графиках',
            'Экспорт аналитических данных'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_reports_per_month' => 200,
            'max_materials_analyzed' => 5000,
            'data_retention_months' => 24,
            'concurrent_analytics' => 5
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'analytics_settings' => [
                'update_frequency_hours' => 24,
                'include_inactive_materials' => false,
                'calculate_trends' => true,
                'generate_forecasts' => true,
                'include_cost_analysis' => true,
                'data_retention_months' => 24
            ],
            'reporting_settings' => [
                'default_period_days' => 30,
                'include_charts' => true,
                'export_format' => 'excel',
                'auto_generate_monthly' => false,
                'email_reports_to_managers' => false,
                'compress_large_reports' => true
            ],
            'threshold_settings' => [
                'low_stock_threshold_percent' => 10,
                'high_usage_threshold' => 1000,
                'cost_variance_alert_percent' => 15,
                'trend_analysis_min_days' => 30
            ],
            'visualization_settings' => [
                'chart_type' => 'line',
                'color_scheme' => 'default',
                'show_data_points' => true,
                'include_trend_lines' => true,
                'animate_charts' => false
            ],
            'cache_settings' => [
                'cache_reports' => true,
                'cache_duration_hours' => 6,
                'cache_heavy_queries' => true,
                'preload_common_reports' => false
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['analytics_settings']['update_frequency_hours']) && 
            (!is_int($settings['analytics_settings']['update_frequency_hours']) || 
             $settings['analytics_settings']['update_frequency_hours'] < 1 || 
             $settings['analytics_settings']['update_frequency_hours'] > 168)) {
            return false;
        }

        if (isset($settings['threshold_settings']['low_stock_threshold_percent']) && 
            (!is_numeric($settings['threshold_settings']['low_stock_threshold_percent']) || 
             $settings['threshold_settings']['low_stock_threshold_percent'] < 0 || 
             $settings['threshold_settings']['low_stock_threshold_percent'] > 100)) {
            return false;
        }

        if (isset($settings['reporting_settings']['default_period_days']) && 
            (!is_int($settings['reporting_settings']['default_period_days']) || 
             $settings['reporting_settings']['default_period_days'] < 1)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля аналитики материалов');
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
