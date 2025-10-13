<?php

namespace App\BusinessModules\Features\AdvancedWarehouse;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\BillableInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class AdvancedWarehouseModule implements ModuleInterface, BillableInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Продвинутое управление складом';
    }

    public function getSlug(): string
    {
        return 'advanced-warehouse';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Продвинутое складское управление с штрихкодами, RFID, аналитикой и автоматизацией';
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
            file_get_contents(config_path('ModuleList/features/advanced-warehouse.json')),
            true
        );
    }

    public function install(): void
    {
        // Миграции будут выполнены автоматически
    }

    public function uninstall(): void
    {
        // ВНИМАНИЕ: данные продвинутого склада не удаляются
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля
    }

    public function canActivate(int $organizationId): bool
    {
        // Проверяем что базовый склад активирован
        $accessController = app(\App\Modules\Core\AccessController::class);
        return $accessController->hasModuleAccess($organizationId, 'basic-warehouse');
    }

    public function getDependencies(): array
    {
        return [
            'basic-warehouse',
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
            'advanced_warehouse.view',
            'advanced_warehouse.multiple_warehouses',
            'advanced_warehouse.zones',
            'advanced_warehouse.barcode',
            'advanced_warehouse.rfid',
            'advanced_warehouse.qr_codes',
            'advanced_warehouse.batch_tracking',
            'advanced_warehouse.serial_tracking',
            'advanced_warehouse.reservations',
            'advanced_warehouse.auto_reorder',
            'advanced_warehouse.analytics',
            'advanced_warehouse.forecasts',
            'advanced_warehouse.mobile_scanner',
            'advanced_warehouse.api_access'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'До 20 складов с зонами хранения',
            'Адресное хранение (стеллаж-полка-ячейка)',
            'Штрихкоды (генерация, печать, сканирование)',
            'RFID метки и отслеживание',
            'QR коды для быстрого поиска',
            'Умная инвентаризация со сканером',
            'Мобильное приложение со сканером',
            'Автоматическая сверка расхождений',
            'Партионный учет материалов',
            'Серийный учет оборудования',
            'Резервирование активов для проектов',
            'Автоматическое пополнение (min/max)',
            'Прогноз потребности в материалах',
            'Анализ оборачиваемости',
            'ABC/XYZ анализ запасов',
            'Топ используемых активов',
            'Оптимизация запасов',
            'API для интеграции с 1С',
            'Экспорт в Excel/PDF',
            'Webhooks для внешних систем'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_warehouses' => 20,
            'max_zones_per_warehouse' => 50,
            'max_barcodes_per_asset' => 10,
            'max_rfid_tags_per_asset' => 5,
            'api_rate_limit_per_minute' => 200,
            'max_inventory_acts_per_month' => -1, // неограниченно
            'max_reservations' => 1000,
            'max_auto_reorder_rules' => 100
        ];
    }

    // BillableInterface
    public function getPrice(): float
    {
        return 3990.0;
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
            'base_price' => 3990,
            'currency' => 'RUB',
            'included_in_plans' => ['enterprise'],
            'duration_days' => 30,
            'trial_days' => 14
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
            // Множественные склады
            'enable_multiple_warehouses' => true,
            'enable_zones' => true,
            'enable_location_tracking' => true,
            
            // Автоматизация
            'enable_barcode' => true,
            'enable_rfid' => true,
            'enable_qr_codes' => true,
            'barcode_format' => 'EAN13',
            'auto_generate_barcodes' => true,
            
            // Учет
            'enable_batch_tracking' => true,
            'enable_serial_tracking' => true,
            'enable_expiry_tracking' => true,
            
            // Резервирование
            'enable_reservations' => true,
            'reservation_timeout_hours' => 24,
            'auto_release_expired' => true,
            
            // Автопополнение
            'enable_auto_reorder' => true,
            'auto_reorder_check_interval' => 3600, // секунд
            'min_stock_threshold_percent' => 20,
            
            // Аналитика
            'enable_analytics' => true,
            'enable_forecasting' => true,
            'forecast_horizon_days' => 90,
            'enable_abc_analysis' => true,
            
            // API
            'enable_api_access' => true,
            'api_rate_limit' => 200,
            'enable_webhooks' => true,
            
            // Производительность
            'cache_analytics' => true,
            'cache_ttl' => 600,
            'enable_async_processing' => true
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['reservation_timeout_hours']) &&
            (!is_int($settings['reservation_timeout_hours']) ||
             $settings['reservation_timeout_hours'] < 1)) {
            return false;
        }

        if (isset($settings['api_rate_limit']) &&
            (!is_int($settings['api_rate_limit']) ||
             $settings['api_rate_limit'] < 10 ||
             $settings['api_rate_limit'] > 1000)) {
            return false;
        }

        if (isset($settings['min_stock_threshold_percent']) &&
            (!is_numeric($settings['min_stock_threshold_percent']) ||
             $settings['min_stock_threshold_percent'] < 0 ||
             $settings['min_stock_threshold_percent'] > 100)) {
            return false;
        }

        if (isset($settings['forecast_horizon_days']) &&
            (!is_int($settings['forecast_horizon_days']) ||
             $settings['forecast_horizon_days'] < 7 ||
             $settings['forecast_horizon_days'] > 365)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля продвинутого склада');
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

            \Illuminate\Support\Facades\Cache::forget("advanced_warehouse_settings_{$organizationId}");
        }
    }

    public function getSettings(int $organizationId): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "advanced_warehouse_settings_{$organizationId}",
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

