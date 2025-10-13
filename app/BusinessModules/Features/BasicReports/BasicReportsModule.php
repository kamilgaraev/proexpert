<?php

namespace App\BusinessModules\Features\BasicReports;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class BasicReportsModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Базовые отчеты';
    }

    public function getSlug(): string
    {
        return 'basic-reports';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Базовые отчеты по материалам, работам и проектам';
    }

    public function getType(): ModuleType
    {
        return ModuleType::FEATURE;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::FREE;
    }

    public function getManifest(): array
    {
        return json_decode(file_get_contents(config_path('ModuleList/features/basic-reports.json')), true);
    }

    public function install(): void
    {
        // Модуль использует существующие таблицы отчетов
    }

    public function uninstall(): void
    {
        // Базовые отчеты нельзя удалить - это системная функциональность
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля
    }

    public function canActivate(int $organizationId): bool
    {
        // Базовые отчеты доступны всем организациям
        return true;
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
            'basic_reports.view',
            'basic_reports.material_usage',
            'basic_reports.work_completion', 
            'basic_reports.project_summary',
            'basic_reports.contract_payments',
            'basic_reports.contractor_settlements',
            'basic_reports.warehouse_stock',
            'basic_reports.material_movements',
            'basic_reports.time_tracking',
            'basic_reports.project_profitability',
            'basic_reports.project_timelines',
            'basic_reports.export_excel',
            'basic_reports.export_pdf'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Отчет по использованию материалов',
            'Отчет по выполненным работам', 
            'Сводка по статусам проектов',
            'Отчет по контрактам и платежам',
            'Отчет по расчетам с подрядчиками',
            'Отчет по остаткам на складах',
            'Отчет по движению материалов',
            'Отчет по учету рабочего времени',
            'Отчет по рентабельности проектов',
            'Отчет по срокам выполнения проектов',
            'Экспорт в Excel и PDF',
            'Фильтрация по датам и параметрам',
            'Детальная аналитика'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_reports_per_month' => 200,
            'max_export_rows' => 5000,
            'retention_days' => 30
        ];
    }

    // ConfigurableInterface
    public function getDefaultSettings(): array
    {
        return [
            'auto_cleanup_enabled' => true,
            'default_date_range' => 30,
            'default_export_format' => 'excel',
            'email_notifications' => false,
            'show_summary_charts' => true,
            'max_concurrent_reports' => 3,
            'cache_reports' => true,
            'cache_duration_minutes' => 60
        ];
    }

    public function validateSettings(array $settings): bool
    {
        $allowedFormats = ['excel', 'pdf', 'csv'];
        
        if (isset($settings['default_export_format']) && 
            !in_array($settings['default_export_format'], $allowedFormats)) {
            return false;
        }

        if (isset($settings['default_date_range']) && 
            (!is_int($settings['default_date_range']) || $settings['default_date_range'] < 1)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля базовых отчетов');
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
