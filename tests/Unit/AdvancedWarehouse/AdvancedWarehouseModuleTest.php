<?php

namespace Tests\Unit\AdvancedWarehouse;

use Tests\TestCase;
use App\BusinessModules\Features\AdvancedWarehouse\AdvancedWarehouseModule;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class AdvancedWarehouseModuleTest extends TestCase
{
    protected AdvancedWarehouseModule $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = new AdvancedWarehouseModule();
    }

    public function test_module_has_correct_basic_info()
    {
        $this->assertEquals('Продвинутое управление складом', $this->module->getName());
        $this->assertEquals('advanced-warehouse', $this->module->getSlug());
        $this->assertEquals('1.0.0', $this->module->getVersion());
        $this->assertNotEmpty($this->module->getDescription());
    }

    public function test_module_type_is_feature()
    {
        $this->assertEquals(ModuleType::FEATURE, $this->module->getType());
    }

    public function test_billing_model_is_subscription()
    {
        $this->assertEquals(BillingModel::SUBSCRIPTION, $this->module->getBillingModel());
    }

    public function test_module_has_correct_price()
    {
        $this->assertEquals(3990.0, $this->module->getPrice());
        $this->assertEquals('RUB', $this->module->getCurrency());
        $this->assertEquals(30, $this->module->getDurationDays());
    }

    public function test_module_has_pricing_config()
    {
        $pricing = $this->module->getPricingConfig();
        
        $this->assertIsArray($pricing);
        $this->assertEquals(3990, $pricing['base_price']);
        $this->assertEquals('RUB', $pricing['currency']);
        $this->assertContains('enterprise', $pricing['included_in_plans']);
        $this->assertEquals(30, $pricing['duration_days']);
        $this->assertEquals(14, $pricing['trial_days']);
    }

    public function test_module_depends_on_basic_warehouse()
    {
        $dependencies = $this->module->getDependencies();
        
        $this->assertIsArray($dependencies);
        $this->assertContains('basic-warehouse', $dependencies);
        $this->assertContains('materials', $dependencies);
        $this->assertContains('organizations', $dependencies);
    }

    public function test_module_has_advanced_permissions()
    {
        $permissions = $this->module->getPermissions();
        
        $this->assertIsArray($permissions);
        $this->assertContains('advanced_warehouse.view', $permissions);
        $this->assertContains('advanced_warehouse.multiple_warehouses', $permissions);
        $this->assertContains('advanced_warehouse.barcode', $permissions);
        $this->assertContains('advanced_warehouse.rfid', $permissions);
        $this->assertContains('advanced_warehouse.batch_tracking', $permissions);
        $this->assertContains('advanced_warehouse.serial_tracking', $permissions);
        $this->assertContains('advanced_warehouse.reservations', $permissions);
        $this->assertContains('advanced_warehouse.auto_reorder', $permissions);
        $this->assertContains('advanced_warehouse.analytics', $permissions);
        $this->assertContains('advanced_warehouse.forecasts', $permissions);
        $this->assertCount(14, $permissions);
    }

    public function test_module_has_advanced_features()
    {
        $features = $this->module->getFeatures();
        
        $this->assertIsArray($features);
        $this->assertNotEmpty($features);
        $this->assertCount(20, $features);
    }

    public function test_module_has_advanced_limits()
    {
        $limits = $this->module->getLimits();
        
        $this->assertIsArray($limits);
        $this->assertEquals(20, $limits['max_warehouses']);
        $this->assertEquals(50, $limits['max_zones_per_warehouse']);
        $this->assertEquals(10, $limits['max_barcodes_per_asset']);
        $this->assertEquals(5, $limits['max_rfid_tags_per_asset']);
        $this->assertEquals(200, $limits['api_rate_limit_per_minute']);
        $this->assertEquals(-1, $limits['max_inventory_acts_per_month']); // неограниченно
        $this->assertEquals(1000, $limits['max_reservations']);
        $this->assertEquals(100, $limits['max_auto_reorder_rules']);
    }

    public function test_module_has_comprehensive_default_settings()
    {
        $settings = $this->module->getDefaultSettings();
        
        $this->assertIsArray($settings);
        
        // Множественные склады
        $this->assertTrue($settings['enable_multiple_warehouses']);
        $this->assertTrue($settings['enable_zones']);
        $this->assertTrue($settings['enable_location_tracking']);
        
        // Автоматизация
        $this->assertTrue($settings['enable_barcode']);
        $this->assertTrue($settings['enable_rfid']);
        $this->assertTrue($settings['enable_qr_codes']);
        $this->assertEquals('EAN13', $settings['barcode_format']);
        $this->assertTrue($settings['auto_generate_barcodes']);
        
        // Учет
        $this->assertTrue($settings['enable_batch_tracking']);
        $this->assertTrue($settings['enable_serial_tracking']);
        
        // Резервирование
        $this->assertTrue($settings['enable_reservations']);
        $this->assertEquals(24, $settings['reservation_timeout_hours']);
        
        // Аналитика
        $this->assertTrue($settings['enable_analytics']);
        $this->assertTrue($settings['enable_forecasting']);
        $this->assertEquals(90, $settings['forecast_horizon_days']);
        
        // API
        $this->assertTrue($settings['enable_api_access']);
        $this->assertEquals(200, $settings['api_rate_limit']);
        $this->assertTrue($settings['enable_webhooks']);
    }

    public function test_validate_settings_accepts_valid_settings()
    {
        $validSettings = [
            'reservation_timeout_hours' => 48,
            'api_rate_limit' => 500,
            'min_stock_threshold_percent' => 15,
            'forecast_horizon_days' => 120,
        ];
        
        $this->assertTrue($this->module->validateSettings($validSettings));
    }

    public function test_validate_settings_rejects_invalid_reservation_timeout()
    {
        $invalidSettings = [
            'reservation_timeout_hours' => 0,
        ];
        
        $this->assertFalse($this->module->validateSettings($invalidSettings));
    }

    public function test_validate_settings_rejects_invalid_api_rate_limit()
    {
        $invalidSettings = [
            'api_rate_limit' => 5, // меньше минимума 10
        ];
        
        $this->assertFalse($this->module->validateSettings($invalidSettings));
        
        $invalidSettings2 = [
            'api_rate_limit' => 1500, // больше максимума 1000
        ];
        
        $this->assertFalse($this->module->validateSettings($invalidSettings2));
    }

    public function test_validate_settings_rejects_invalid_threshold_percent()
    {
        $invalidSettings = [
            'min_stock_threshold_percent' => 150, // больше 100
        ];
        
        $this->assertFalse($this->module->validateSettings($invalidSettings));
    }

    public function test_validate_settings_rejects_invalid_forecast_horizon()
    {
        $invalidSettings = [
            'forecast_horizon_days' => 5, // меньше минимума 7
        ];
        
        $this->assertFalse($this->module->validateSettings($invalidSettings));
        
        $invalidSettings2 = [
            'forecast_horizon_days' => 400, // больше максимума 365
        ];
        
        $this->assertFalse($this->module->validateSettings($invalidSettings2));
    }
}

