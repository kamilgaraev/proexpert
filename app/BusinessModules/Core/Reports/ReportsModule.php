<?php

namespace App\BusinessModules\Core\Reports;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class ReportsModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Отчеты';
    }

    public function getSlug(): string
    {
        return 'reports';
    }

    public function getVersion(): string
    {
        return '2.0.0';
    }

    public function getDescription(): string
    {
        return 'Унифицированный модуль отчетности, включающий базовые отчеты и расширенную аналитику.';
    }

    public function getType(): ModuleType
    {
        return ModuleType::CORE;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::FREE;
    }

    public function getManifest(): array
    {
        return json_decode(file_get_contents(config_path('ModuleList/core/reports.json')), true);
    }

    public function install(): void
    {
        // Модуль использует существующие миграции
    }

    public function uninstall(): void
    {
        // Системный модуль не может быть удален
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля
    }

    public function canActivate(int $organizationId): bool
    {
        return true;
    }

    public function getDependencies(): array
    {
        return [
            'organizations',
            'users',
            'projects',
        ];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'reports.view',
            'reports.export',
            'reports.manage_templates',
            'reports.custom_reports',
            'reports.share',
            'reports.schedule',
            'reports.official_reports',
            'reports.predictive',
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Базовые отчеты (материалы, работы, проекты)',
            'Конструктор произвольных отчетов',
            'Финансовая аналитика и KPI',
            'Генерация официальных форм (М-29 и др.)',
            'Прогнозирование и ML-аналитика',
            'Автоматическая рассылка отчетов',
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_custom_reports' => 10,
            'max_scheduled_reports' => 5,
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'default_export_format' => 'pdf',
            'enable_external_data_sources' => false,
            'report_retention_days' => 30,
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['default_export_format']) && !in_array($settings['default_export_format'], ['pdf', 'xlsx', 'csv'])) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля отчетов');
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
