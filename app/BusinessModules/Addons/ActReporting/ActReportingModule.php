<?php

namespace App\BusinessModules\Addons\ActReporting;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class ActReportingModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Управление актами';
    }

    public function getSlug(): string
    {
        return 'act-reporting';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Система управления актами выполненных работ и отчетностью';
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
        return json_decode(file_get_contents(config_path('ModuleList/addons/act-reporting.json')), true);
    }

    public function install(): void
    {
        // Модуль использует существующие таблицы актов
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
               $accessController->hasModuleAccess($organizationId, 'contract-management') &&
               $accessController->hasModuleAccess($organizationId, 'workflow-management');
    }

    public function getDependencies(): array
    {
        return ['organizations', 'users', 'contract-management', 'workflow-management'];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'act_reports.view',
            'act_reports.create',
            'act_reports.edit',
            'act_reports.delete',
            'act_reports.contracts.view',
            'act_reports.works.view',
            'act_reports.works.update',
            'act_reports.export.pdf',
            'act_reports.export.excel',
            'act_reports.bulk_export.excel',
            'act_reports.download_pdf'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Создание актов выполненных работ',
            'Привязка актов к контрактам',
            'Управление составом работ в акте',
            'Просмотр доступных работ для актов',
            'Экспорт актов в PDF и Excel',
            'Массовый экспорт актов',
            'Сохранение и скачивание PDF актов',
            'Фильтрация актов по различным критериям',
            'Интеграция с системой контрактов',
            'Автоматическое формирование отчетности'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_acts_per_month' => 500,
            'max_works_per_act' => 100,
            'max_export_size_mb' => 100,
            'concurrent_exports' => 3
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'act_settings' => [
                'auto_generate_numbers' => true,
                'number_prefix' => 'АКТ',
                'include_year_in_number' => true,
                'reset_numbering_yearly' => true,
                'require_digital_signature' => false
            ],
            'export_settings' => [
                'default_format' => 'pdf',
                'include_company_logo' => true,
                'include_signatures' => true,
                'watermark_drafts' => true,
                'compress_pdf' => false,
                'max_concurrent_exports' => 3
            ],
            'workflow_settings' => [
                'require_approval_before_export' => true,
                'allow_retroactive_acts' => false,
                'auto_link_to_contracts' => true,
                'validate_work_completion' => true
            ],
            'notification_settings' => [
                'notify_on_act_created' => true,
                'notify_on_export_ready' => true,
                'notify_contract_manager' => true,
                'email_notifications' => true
            ],
            'storage_settings' => [
                'store_generated_pdfs' => true,
                'pdf_retention_days' => 365,
                'compress_stored_files' => true,
                'backup_to_cloud' => false
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['export_settings']['max_concurrent_exports']) && 
            (!is_int($settings['export_settings']['max_concurrent_exports']) || 
             $settings['export_settings']['max_concurrent_exports'] < 1 || 
             $settings['export_settings']['max_concurrent_exports'] > 10)) {
            return false;
        }

        if (isset($settings['storage_settings']['pdf_retention_days']) && 
            (!is_int($settings['storage_settings']['pdf_retention_days']) || 
             $settings['storage_settings']['pdf_retention_days'] < 30)) {
            return false;
        }

        $allowedFormats = ['pdf', 'excel', 'word'];
        if (isset($settings['export_settings']['default_format']) && 
            !in_array($settings['export_settings']['default_format'], $allowedFormats)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля управления актами');
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
