<?php

namespace App\BusinessModules\Features\CatalogManagement;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class CatalogManagementModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Управление справочниками';
    }

    public function getSlug(): string
    {
        return 'catalog-management';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Система управления справочниками - материалы, подрядчики, единицы измерения';
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
        return json_decode(file_get_contents(config_path('ModuleList/features/catalog-management.json')), true);
    }

    public function install(): void
    {
        // Модуль использует существующие таблицы справочников
    }

    public function uninstall(): void
    {
        // Системный модуль нельзя удалить
        throw new \Exception('Системный модуль управления справочниками не может быть удален');
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля
    }

    public function canActivate(int $organizationId): bool
    {
        // Проверяем что базовые модули активированы
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
            'materials.view',
            'materials.create',
            'materials.edit',
            'materials.delete',
            'materials.import',
            'materials.export',
            'materials.balances.view',
            'materials.consumption_rates.view',
            'materials.consumption_rates.edit',
            'suppliers.view',
            'suppliers.create', 
            'suppliers.edit',
            'suppliers.delete',
            'contractors.view',
            'contractors.create',
            'contractors.edit', 
            'contractors.delete',
            'work_types.view',
            'work_types.create',
            'work_types.edit',
            'work_types.delete',
            'work_types.materials.manage',
            'measurement_units.view',
            'measurement_units.create',
            'measurement_units.edit',
            'measurement_units.delete',
            'cost_categories.view',
            'cost_categories.create',
            'cost_categories.edit',
            'cost_categories.delete',
            'cost_categories.import'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Управление материалами',
            'Импорт/экспорт материалов',
            'Балансы материалов',
            'Нормы списания материалов',
            'Управление поставщиками',
            'Управление подрядчиками', 
            'Управление видами работ',
            'Связь материалов с видами работ',
            'Единицы измерения',
            'Категории затрат',
            'Поиск по справочникам',
            'Валидация для бухгалтерских систем'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_materials' => null,
            'max_suppliers' => null,
            'max_contractors' => null,
            'max_work_types' => null
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'material_settings' => [
                'auto_generate_codes' => true,
                'require_supplier' => false,
                'track_expiry_dates' => true,
                'enable_batch_tracking' => false,
                'alert_on_low_stock' => true,
                'stock_alert_threshold' => 10
            ],
            'supplier_settings' => [
                'require_inn' => true,
                'validate_contact_info' => true,
                'track_payment_terms' => true,
                'enable_rating_system' => false
            ],
            'contractor_settings' => [
                'require_license' => false,
                'track_certifications' => true,
                'enable_performance_tracking' => true,
                'require_insurance_info' => false
            ],
            'work_type_settings' => [
                'auto_link_materials' => true,
                'suggest_similar_materials' => true,
                'track_consumption_norms' => true,
                'validate_material_compatibility' => true
            ],
            'import_settings' => [
                'validate_data_before_import' => true,
                'create_missing_suppliers' => false,
                'update_existing_records' => true,
                'backup_before_import' => true
            ],
            'integration_settings' => [
                'enable_accounting_sync' => false,
                'validate_for_1c' => false,
                'validate_for_sbis' => false,
                'sync_frequency_hours' => 24
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['material_settings']['stock_alert_threshold']) && 
            (!is_numeric($settings['material_settings']['stock_alert_threshold']) || 
             $settings['material_settings']['stock_alert_threshold'] < 0)) {
            return false;
        }

        if (isset($settings['integration_settings']['sync_frequency_hours']) && 
            (!is_int($settings['integration_settings']['sync_frequency_hours']) || 
             $settings['integration_settings']['sync_frequency_hours'] < 1)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля управления справочниками');
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
