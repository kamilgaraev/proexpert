<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\SystemLogs;

use App\Enums\BillingModel;
use App\Enums\ModuleType;
use App\Modules\Contracts\ConfigurableInterface;
use App\Modules\Contracts\ModuleInterface;

class SystemLogsModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Журнал действий';
    }

    public function getSlug(): string
    {
        return 'system-logs';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Понятная история действий пользователей и системных событий организации';
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
        return json_decode(file_get_contents(config_path('ModuleList/addons/system-logs.json')), true);
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function upgrade(string $fromVersion): void
    {
    }

    public function canActivate(int $organizationId): bool
    {
        $accessController = app(\App\Modules\Core\AccessController::class);

        return $accessController->hasModuleAccess($organizationId, 'organizations')
            && $accessController->hasModuleAccess($organizationId, 'users');
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
            'logs.material_usage.view',
            'logs.work_completion.view',
            'logs.system.view',
            'logs.export',
            'logs.filter',
            'logs.search',
            'activity-events.view',
            'activity-events.export',
            'activity-events.view_security',
            'activity-events.view_technical_context',
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Просмотр понятной истории действий пользователей',
            'Фильтрация по пользователям, модулям, объектам и датам',
            'Безопасная детализация изменений без чувствительных данных',
            'Экспорт журнала действий',
            'Контроль действий безопасности и прав доступа',
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_log_entries_per_day' => 50000,
            'retention_days' => 365,
            'max_export_rows' => 10000,
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'logging_settings' => [
                'log_material_usage' => true,
                'log_work_completion' => true,
                'log_system_operations' => true,
                'log_user_actions' => true,
                'detailed_logging' => false,
            ],
            'retention_settings' => [
                'retention_days' => 365,
                'auto_archive' => true,
                'archive_compression' => true,
                'archive_location' => 'local',
            ],
            'export_settings' => [
                'max_export_rows' => 10000,
                'allowed_formats' => ['csv'],
                'include_metadata' => false,
            ],
            'monitoring_settings' => [
                'enable_real_time_alerts' => false,
                'alert_on_errors' => true,
                'alert_threshold_per_hour' => 100,
            ],
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['retention_settings']['retention_days'])) {
            $retentionDays = $settings['retention_settings']['retention_days'];

            return is_int($retentionDays) && $retentionDays > 0;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки журнала действий');
        }

        $activation = \App\Models\OrganizationModuleActivation::where('organization_id', $organizationId)
            ->whereHas('module', function ($query) {
                $query->where('slug', $this->getSlug());
            })
            ->first();

        if ($activation) {
            $currentSettings = $activation->module_settings ?? [];
            $activation->update([
                'module_settings' => array_merge($currentSettings, $settings),
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
