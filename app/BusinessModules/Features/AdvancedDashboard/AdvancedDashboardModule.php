<?php

namespace App\BusinessModules\Features\AdvancedDashboard;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\BillableInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class AdvancedDashboardModule implements ModuleInterface, BillableInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Продвинутый дашборд';
    }

    public function getSlug(): string
    {
        return 'advanced-dashboard';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Расширенная аналитика, предиктивные модели и кастомизация дашборда';
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
        return json_decode(
            file_get_contents(config_path('ModuleList/features/advanced-dashboard.json')), 
            true
        );
    }

    public function install(): void
    {
        // Миграции будут выполнены автоматически
        // Создание демо-дашбордов для новых пользователей
    }

    public function uninstall(): void
    {
        // Очистка данных модуля (дашборды, алерты, scheduled_reports)
        // ВНИМАНИЕ: данные пользователей не удаляются, только отключается доступ
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля при изменении версии
    }

    public function canActivate(int $organizationId): bool
    {
        // Проверяем что базовый дашборд активирован
        $accessController = app(\App\Modules\Core\AccessController::class);
        return $accessController->hasModuleAccess($organizationId, 'dashboard-widgets');
    }

    public function getDependencies(): array
    {
        return [
            'dashboard-widgets',
            'organizations',
            'users',
            'contracts',
            'completed-works',
            'projects',
            'materials'
        ];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'advanced_dashboard.view',
            'advanced_dashboard.financial_analytics',
            'advanced_dashboard.predictive_analytics',
            'advanced_dashboard.hr_analytics',
            'advanced_dashboard.multiple_dashboards',
            'advanced_dashboard.realtime_updates',
            'advanced_dashboard.alerts',
            'advanced_dashboard.export',
            'advanced_dashboard.api_access',
            'advanced_dashboard.custom_widgets'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Множественные именованные дашборды (до 10)',
            'Финансовая аналитика (Cash Flow, P&L, ROI)',
            'Предиктивная аналитика (прогноз завершения контрактов, риски бюджета)',
            'HR-аналитика (KPI сотрудников, загрузка ресурсов)',
            'Real-time обновления данных виджетов',
            'Система алертов и уведомлений',
            'Экспорт дашбордов в PDF и Excel',
            'Публичный API для интеграции',
            'Создание кастомных виджетов',
            'Глобальные фильтры данных',
            'Сравнительная аналитика',
            'Шаблоны дашбордов для ролей'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_dashboards_per_user' => 10,
            'max_alerts_per_dashboard' => 20,
            'data_retention_months' => 36,
            'max_api_requests_per_minute' => 100
        ];
    }

    // BillableInterface
    public function getPrice(): float
    {
        return 4990.0;
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
            'base_price' => 4990,
            'currency' => 'RUB',
            'included_in_plans' => ['profi', 'enterprise'],
            'duration_days' => 30,
            'trial_days' => 7
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
            // Финансовая аналитика
            'enable_financial_analytics' => true,
            'enable_cash_flow' => true,
            'enable_profit_loss' => true,
            'enable_roi_calculation' => true,
            
            // Предиктивная аналитика
            'enable_predictive_analytics' => true,
            'prediction_confidence_threshold' => 0.75, // 75%
            'forecast_horizon_months' => 6,
            
            // Real-time обновления
            'enable_realtime_updates' => true,
            'widget_refresh_interval' => 300, // секунд (5 минут)
            'websocket_enabled' => true,
            
            // Алерты
            'enable_alerts' => true,
            'alert_check_interval' => 3600, // секунд (1 час)
            'max_alerts_per_dashboard' => 20,
            
            // Экспорт
            'enable_pdf_export' => true,
            'enable_excel_export' => true,
            'enable_scheduled_reports' => true,
            
            // API доступ
            'enable_api_access' => true,
            'api_rate_limit_per_minute' => 100,
            
            // Дашборды
            'max_dashboards_per_user' => 10,
            'enable_dashboard_sharing' => true,
            'enable_dashboard_templates' => true,
            
            // Кеширование
            'cache_ttl' => 300, // секунд
            'enable_aggressive_caching' => true,
            
            // Производительность
            'enable_lazy_loading' => true,
            'max_widgets_per_dashboard' => 15,
            
            // Уведомления
            'notification_channels' => [
                'email' => true,
                'in_app' => true
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        // Валидация интервалов
        if (isset($settings['widget_refresh_interval']) && 
            (!is_int($settings['widget_refresh_interval']) || 
             $settings['widget_refresh_interval'] < 60)) {
            return false;
        }

        if (isset($settings['alert_check_interval']) && 
            (!is_int($settings['alert_check_interval']) || 
             $settings['alert_check_interval'] < 300)) {
            return false;
        }

        // Валидация лимитов
        if (isset($settings['api_rate_limit_per_minute']) && 
            (!is_int($settings['api_rate_limit_per_minute']) || 
             $settings['api_rate_limit_per_minute'] < 10 || 
             $settings['api_rate_limit_per_minute'] > 1000)) {
            return false;
        }

        if (isset($settings['max_dashboards_per_user']) && 
            (!is_int($settings['max_dashboards_per_user']) || 
             $settings['max_dashboards_per_user'] < 1 || 
             $settings['max_dashboards_per_user'] > 50)) {
            return false;
        }

        // Валидация порога уверенности предиктивной аналитики
        if (isset($settings['prediction_confidence_threshold']) && 
            (!is_float($settings['prediction_confidence_threshold']) && 
             !is_int($settings['prediction_confidence_threshold']) || 
             $settings['prediction_confidence_threshold'] < 0.5 || 
             $settings['prediction_confidence_threshold'] > 1.0)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля продвинутого дашборда');
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
            
            // Инвалидация кеша настроек
            \Illuminate\Support\Facades\Cache::forget("advanced_dashboard_settings_{$organizationId}");
        }
    }

    public function getSettings(int $organizationId): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "advanced_dashboard_settings_{$organizationId}",
            3600, // 1 час
            function () use ($organizationId) {
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
        );
    }
}

