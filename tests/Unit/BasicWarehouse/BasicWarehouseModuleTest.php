<?php

namespace Tests\Unit\BasicWarehouse;

use Tests\TestCase;
use App\BusinessModules\Features\BasicWarehouse\BasicWarehouseModule;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class BasicWarehouseModuleTest extends TestCase
{
    protected BasicWarehouseModule $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = new BasicWarehouseModule();
    }

    public function test_module_has_correct_basic_info()
    {
        $this->assertEquals('Базовое управление складом', $this->module->getName());
        $this->assertEquals('basic-warehouse', $this->module->getSlug());
        $this->assertEquals('1.0.0', $this->module->getVersion());
        $this->assertNotEmpty($this->module->getDescription());
    }

    public function test_module_type_is_feature()
    {
        $this->assertEquals(ModuleType::FEATURE, $this->module->getType());
    }

    public function test_billing_model_is_free()
    {
        $this->assertEquals(BillingModel::FREE, $this->module->getBillingModel());
    }

    public function test_module_has_correct_dependencies()
    {
        $dependencies = $this->module->getDependencies();
        
        $this->assertIsArray($dependencies);
        $this->assertContains('materials', $dependencies);
        $this->assertContains('organizations', $dependencies);
        $this->assertContains('users', $dependencies);
        $this->assertContains('projects', $dependencies);
    }

    public function test_module_has_no_conflicts()
    {
        $conflicts = $this->module->getConflicts();
        
        $this->assertIsArray($conflicts);
        $this->assertEmpty($conflicts);
    }

    public function test_module_has_warehouse_permissions()
    {
        $permissions = $this->module->getPermissions();
        
        $this->assertIsArray($permissions);
        $this->assertContains('warehouse.view', $permissions);
        $this->assertContains('warehouse.manage_stock', $permissions);
        $this->assertContains('warehouse.receipts', $permissions);
        $this->assertContains('warehouse.write_offs', $permissions);
        $this->assertContains('warehouse.transfers', $permissions);
        $this->assertContains('warehouse.inventory', $permissions);
        $this->assertContains('warehouse.reports', $permissions);
        $this->assertCount(7, $permissions);
    }

    public function test_module_has_features_list()
    {
        $features = $this->module->getFeatures();
        
        $this->assertIsArray($features);
        $this->assertNotEmpty($features);
        $this->assertCount(10, $features);
    }

    public function test_module_has_correct_limits()
    {
        $limits = $this->module->getLimits();
        
        $this->assertIsArray($limits);
        $this->assertEquals(1, $limits['max_warehouses']);
        $this->assertEquals(0, $limits['max_zones_per_warehouse']);
        $this->assertFalse($limits['barcode_support']);
        $this->assertFalse($limits['rfid_support']);
        $this->assertFalse($limits['batch_tracking']);
        $this->assertFalse($limits['serial_tracking']);
        $this->assertFalse($limits['auto_reorder']);
        $this->assertFalse($limits['analytics']);
        $this->assertEquals(10, $limits['max_inventory_acts_per_month']);
    }

    public function test_module_can_activate_for_any_organization()
    {
        $organizationId = 1;
        
        $this->assertTrue($this->module->canActivate($organizationId));
    }

    public function test_module_has_default_settings()
    {
        $settings = $this->module->getDefaultSettings();
        
        $this->assertIsArray($settings);
        $this->assertTrue($settings['enable_stock_alerts']);
        $this->assertEquals(10, $settings['low_stock_threshold']);
        $this->assertTrue($settings['enable_auto_calculation']);
        $this->assertTrue($settings['enable_project_transfers']);
        $this->assertTrue($settings['enable_returns']);
        $this->assertEquals('шт', $settings['default_measurement_unit']);
        $this->assertTrue($settings['cache_balances']);
        $this->assertEquals(300, $settings['cache_ttl']);
    }

    public function test_validate_settings_accepts_valid_settings()
    {
        $validSettings = [
            'low_stock_threshold' => 5,
            'cache_ttl' => 600,
        ];
        
        $this->assertTrue($this->module->validateSettings($validSettings));
    }

    public function test_validate_settings_rejects_invalid_threshold()
    {
        $invalidSettings = [
            'low_stock_threshold' => -1,
        ];
        
        $this->assertFalse($this->module->validateSettings($invalidSettings));
    }

    public function test_validate_settings_rejects_invalid_cache_ttl()
    {
        $invalidSettings = [
            'cache_ttl' => 30, // меньше минимума 60
        ];
        
        $this->assertFalse($this->module->validateSettings($invalidSettings));
    }
}

