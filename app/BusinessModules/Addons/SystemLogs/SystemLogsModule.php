<?php

namespace App\BusinessModules\Addons\SystemLogs;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class SystemLogsModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Системные логи';
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
        return 'Система просмотра и анализа логов операций';
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
        // Модуль использует существующие таблицы логов
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
        $accessController = app(\App\Modules\Core\AccessController::class);
        return $accessController->hasModuleAccess($organizationId, 'organizations') &&
               $accessController->hasModuleAccess($organizationId, 'users') &&
               $accessController->hasModuleAccess($organizationId, 'catalog-management') &&
               $accessController->hasModuleAccess($organizationId, 'workflow-management');
    }

    public function getDependencies(): array
    {
        return ['organizations', 'users', 'catalog-management', 'workflow-management'];
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
            'logs.search'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Просмотр логов использования материалов',
            'Просмотр логов выполнения работ',
            'Системные логи операций',
            'Фильтрация логов по критериям',
            'Поиск по логам',
            'Экспорт логов в различные форматы',
            'Автоматическая архивация',
            'Уведомления о критических событиях',
            'Статистика по активности',
            'Интеграция с мониторингом'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_log_entries_per_day' => 50000,
            'retention_days' => 90,
            'max_export_rows' => 10000
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'logging_settings' => [
                'log_material_usage' => true,
                'log_work_completion' => true,
                'log_system_operations' => true,
                'log_user_actions' => false,
                'detailed_logging' => false
            ],
            'retention_settings' => [
                'retention_days' => 90,
                'auto_archive' => true,
                'archive_compression' => true,
                'archive_location' => 'local'
            ],
            'export_settings' => [
                'max_export_rows' => 10000,
                'allowed_formats' => ['csv', 'excel', 'json'],
                'include_metadata' => true
            ],
            'monitoring_settings' => [
                'enable_real_time_alerts' => false,
                'alert_on_errors' => true,
                'alert_threshold_per_hour' => 100
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['retention_settings']['retention_days']) && 
            (!is_int($settings['retention_settings']['retention_days']) || 
             $settings['retention_settings']['retention_days'] < 1)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля системных логов');
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
