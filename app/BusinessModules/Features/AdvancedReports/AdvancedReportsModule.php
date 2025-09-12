<?php

namespace App\BusinessModules\Features\AdvancedReports;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\BillableInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class AdvancedReportsModule implements ModuleInterface, BillableInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Продвинутые отчеты';
    }

    public function getSlug(): string
    {
        return 'advanced-reports';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Расширенная аналитика, детализированные отчеты и бизнес-интеллект';
    }

    public function getType(): ModuleType
    {
        return ModuleType::FEATURE;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::SUBSCRIPTION;
    }

    public function getManifest(): array
    {
        return json_decode(file_get_contents(config_path('ModuleList/features/advanced-reports.json')), true);
    }

    public function install(): void
    {
        // Логика установки дополнительных таблиц для продвинутых отчетов
    }

    public function uninstall(): void
    {
        // Логика удаления данных продвинутых отчетов
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля
    }

    public function canActivate(int $organizationId): bool
    {
        // Проверяем что базовые отчеты активированы
        $accessController = app(\App\Modules\Core\AccessController::class);
        return $accessController->hasModuleAccess($organizationId, 'basic-reports');
    }

    public function getDependencies(): array
    {
        return ['basic-reports', 'organizations', 'users'];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'advanced_reports.view',
            'advanced_reports.foreman_activity',
            'advanced_reports.financial_analytics', 
            'advanced_reports.performance_metrics',
            'advanced_reports.predictive_analytics',
            'advanced_reports.custom_reports',
            'advanced_reports.automated_reports',
            'advanced_reports.api_access',
            'advanced_reports.unlimited_export'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Детализированная активность прорабов',
            'Финансовая аналитика по проектам',
            'Метрики производительности',
            'Прогнозная аналитика',
            'Конструктор отчетов',
            'Автоматическая отправка отчетов',
            'API доступ к отчетам',
            'Неограниченный экспорт данных',
            'Интеграция с BI системами',
            'Дашборды в реальном времени'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_reports_per_month' => -1, // неограниченно
            'max_export_rows' => -1, // неограниченно
            'retention_days' => 365,
            'max_custom_reports' => 50,
            'max_automated_reports' => 10
        ];
    }

    // BillableInterface
    public function getPrice(): float
    {
        return 2900.0;
    }

    public function getCurrency(): string
    {
        return 'RUB';
    }

    public function getDurationDays(): int
    {
        return 30;
    }

    public function getPricingConfig(): array
    {
        return [
            'base_price' => 2900,
            'currency' => 'RUB',
            'included_in_plan' => false,
            'duration_days' => 30
        ];
    }

    public function calculateCost(int $organizationId): float
    {
        return $this->getPrice();
    }

    public function canAfford(int $organizationId): bool
    {
        $organization = \App\Models\Organization::find($organizationId);
        
        if (!$organization) {
            return false;
        }

        $billingEngine = app(\App\Modules\Core\BillingEngine::class);
        $module = \App\Models\Module::where('slug', $this->getSlug())->first();
        
        return $module ? $billingEngine->canAfford($organization, $module) : false;
    }

    // ConfigurableInterface
    public function getDefaultSettings(): array
    {
        return [
            'enable_predictive_analytics' => true,
            'auto_report_generation' => false,
            'api_rate_limit' => 1000, // запросов в час
            'dashboard_refresh_interval' => 300, // секунд
            'enable_real_time_data' => true,
            'max_dashboard_widgets' => 20,
            'enable_custom_formulas' => true,
            'data_retention_years' => 5,
            'enable_data_export_api' => true,
            'notification_settings' => [
                'email_reports' => true,
                'slack_integration' => false,
                'telegram_notifications' => false
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['api_rate_limit']) && 
            (!is_int($settings['api_rate_limit']) || $settings['api_rate_limit'] < 100)) {
            return false;
        }

        if (isset($settings['dashboard_refresh_interval']) && 
            (!is_int($settings['dashboard_refresh_interval']) || $settings['dashboard_refresh_interval'] < 60)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля продвинутых отчетов');
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
