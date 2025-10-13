<?php

namespace App\BusinessModules\Features\BasicWarehouse;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class BasicWarehouseModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Базовое управление складом';
    }

    public function getSlug(): string
    {
        return 'basic-warehouse';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Базовое складское управление с учетом всех типов активов';
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
        return json_decode(
            file_get_contents(config_path('ModuleList/features/basic-warehouse.json')),
            true
        );
    }

    public function install(): void
    {
        // Миграции будут выполнены автоматически
        // Создание центрального склада для организации
    }

    public function uninstall(): void
    {
        // ВНИМАНИЕ: данные склада не удаляются, только отключается доступ
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля при изменении версии
    }

    public function canActivate(int $organizationId): bool
    {
        // Базовый склад доступен всем организациям
        return true;
    }

    public function getDependencies(): array
    {
        return [
            'materials',
            'organizations',
            'users',
            'projects'
        ];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'warehouse.view',
            'warehouse.manage_stock',
            'warehouse.receipts',
            'warehouse.write_offs',
            'warehouse.transfers',
            'warehouse.inventory',
            'warehouse.reports'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Управление всеми типами активов (материалы, оборудование, инструменты, мебель, расходники)',
            'Один центральный склад организации',
            'Приход материалов от поставщиков',
            'Списание материалов на проекты',
            'Перемещение активов между проектами',
            'Возврат активов с проектов',
            'Простая инвентаризация с актами',
            'Базовые отчеты через модуль BasicReports',
            'Учет остатков по активам',
            'История всех операций'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_warehouses' => 1,
            'max_zones_per_warehouse' => 0,
            'barcode_support' => false,
            'rfid_support' => false,
            'batch_tracking' => false,
            'serial_tracking' => false,
            'auto_reorder' => false,
            'analytics' => false,
            'max_inventory_acts_per_month' => 10
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'enable_stock_alerts' => true,
            'low_stock_threshold' => 10,
            'enable_auto_calculation' => true,
            'enable_project_transfers' => true,
            'enable_returns' => true,
            'default_measurement_unit' => 'шт',
            'cache_balances' => true,
            'cache_ttl' => 300,
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['low_stock_threshold']) &&
            (!is_numeric($settings['low_stock_threshold']) ||
             $settings['low_stock_threshold'] < 0)) {
            return false;
        }

        if (isset($settings['cache_ttl']) &&
            (!is_int($settings['cache_ttl']) ||
             $settings['cache_ttl'] < 60)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля базового склада');
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

            \Illuminate\Support\Facades\Cache::forget("basic_warehouse_settings_{$organizationId}");
        }
    }

    public function getSettings(int $organizationId): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "basic_warehouse_settings_{$organizationId}",
            3600,
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

