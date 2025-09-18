<?php

namespace App\BusinessModules\Addons\RateManagement;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class RateManagementModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Управление расценками';
    }

    public function getSlug(): string
    {
        return 'rate-management';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Система управления расценками и коэффициентами';
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
        return json_decode(file_get_contents(config_path('ModuleList/addons/rate-management.json')), true);
    }

    public function install(): void
    {
        // Модуль использует существующие таблицы расценок
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
               $accessController->hasModuleAccess($organizationId, 'contract-management');
    }

    public function getDependencies(): array
    {
        return ['organizations', 'users', 'catalog-management', 'contract-management'];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'rate_coefficients.view',
            'rate_coefficients.create',
            'rate_coefficients.edit',
            'rate_coefficients.delete',
            'rate_coefficients.apply',
            'rate_coefficients.export',
            'rate_coefficients.import'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Управление коэффициентами расценок',
            'Создание новых расценок',
            'Редактирование существующих расценок',
            'Применение коэффициентов к проектам',
            'Массовое применение расценок',
            'Экспорт расценок в Excel',
            'Импорт расценок из внешних источников',
            'История изменения расценок',
            'Автоматический пересчет стоимости',
            'Интеграция с системой контрактов'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_rate_coefficients' => 1000,
            'max_bulk_apply_items' => 500,
            'history_retention_months' => 24
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'rate_settings' => [
                'auto_apply_rates' => false,
                'allow_negative_rates' => false,
                'decimal_places' => 2,
                'default_currency' => 'RUB',
                'rate_validation_enabled' => true
            ],
            'calculation_settings' => [
                'recalculate_on_rate_change' => true,
                'batch_calculation_enabled' => true,
                'max_batch_size' => 500,
                'preserve_manual_overrides' => true
            ],
            'import_export_settings' => [
                'allowed_import_formats' => ['xlsx', 'csv'],
                'export_include_history' => false,
                'auto_backup_before_import' => true,
                'validate_imported_rates' => true
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['rate_settings']['decimal_places']) && 
            (!is_int($settings['rate_settings']['decimal_places']) || 
             $settings['rate_settings']['decimal_places'] < 0 || 
             $settings['rate_settings']['decimal_places'] > 6)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля управления расценками');
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
