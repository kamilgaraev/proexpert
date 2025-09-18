<?php

namespace App\BusinessModules\Services\DashboardWidgets;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class DashboardWidgetsModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Виджеты дашборда';
    }

    public function getSlug(): string
    {
        return 'dashboard-widgets';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Система управления виджетами главного дашборда';
    }

    public function getType(): ModuleType
    {
        return ModuleType::SERVICE;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::FREE;
    }

    public function getManifest(): array
    {
        return json_decode(file_get_contents(config_path('ModuleList/services/dashboard-widgets.json')), true);
    }

    public function install(): void
    {
        // Базовый системный модуль автоматически активируется
    }

    public function uninstall(): void
    {
        // Системный модуль нельзя удалять
        throw new \Exception('Системный модуль нельзя удалять');
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля
    }

    public function canActivate(int $organizationId): bool
    {
        $accessController = app(\App\Modules\Core\AccessController::class);
        return $accessController->hasModuleAccess($organizationId, 'organizations') &&
               $accessController->hasModuleAccess($organizationId, 'users');
    }

    public function getDependencies(): array
    {
        return ['organizations', 'users'];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'dashboard.view',
            'dashboard.timeseries.view',
            'dashboard.top_entities.view',
            'dashboard.history.view',
            'dashboard.limits.view',
            'dashboard.contracts.statistics',
            'dashboard.recent_activity.view',
            'dashboard.site_requests.statistics',
            'dashboard.widgets.customize',
            'dashboard.settings.manage'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Основной дашборд с метриками',
            'Временные ряды данных',
            'Топ сущностей по различным критериям',
            'История активности',
            'Контроль лимитов и ограничений',
            'Статистика контрактов',
            'Последняя активность пользователей',
            'Статистика заявок с объектов',
            'Настройка виджетов пользователем',
            'Управление настройками дашборда',
            'Реестр доступных виджетов',
            'Экспорт данных дашборда'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_widgets_per_dashboard' => 20,
            'max_custom_dashboards' => 5
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'widget_settings' => [
                'default_widget_count' => 6,
                'enable_widget_customization' => true,
                'auto_refresh_enabled' => true,
                'refresh_interval_seconds' => 300,
                'show_widget_titles' => true
            ],
            'dashboard_settings' => [
                'enable_multiple_dashboards' => true,
                'allow_dashboard_sharing' => false,
                'show_system_metrics' => true,
                'compact_mode_enabled' => false
            ],
            'data_settings' => [
                'cache_widget_data' => true,
                'cache_duration_minutes' => 15,
                'show_real_time_data' => false,
                'max_data_points' => 100
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['widget_settings']['refresh_interval_seconds']) && 
            (!is_int($settings['widget_settings']['refresh_interval_seconds']) || 
             $settings['widget_settings']['refresh_interval_seconds'] < 30)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля виджетов дашборда');
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
