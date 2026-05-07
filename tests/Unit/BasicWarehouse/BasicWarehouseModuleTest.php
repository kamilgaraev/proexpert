<?php

declare(strict_types=1);

namespace Tests\Unit\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\BasicWarehouseModule;
use App\Enums\BillingModel;
use App\Enums\ModuleType;
use PHPUnit\Framework\TestCase;

class BasicWarehouseModuleTest extends TestCase
{
    protected BasicWarehouseModule $module;

    protected function setUp(): void
    {
        parent::setUp();

        $this->module = new BasicWarehouseModule();
    }

    public function test_module_has_correct_basic_info(): void
    {
        $this->assertEquals('Универсальный складской модуль', $this->module->getName());
        $this->assertEquals('basic-warehouse', $this->module->getSlug());
        $this->assertEquals('1.0.0', $this->module->getVersion());
        $this->assertNotEmpty($this->module->getDescription());
    }

    public function test_module_type_is_feature(): void
    {
        $this->assertEquals(ModuleType::FEATURE, $this->module->getType());
    }

    public function test_billing_model_is_free(): void
    {
        $this->assertEquals(BillingModel::FREE, $this->module->getBillingModel());
    }

    public function test_module_has_correct_dependencies(): void
    {
        $dependencies = $this->module->getDependencies();

        $this->assertIsArray($dependencies);
        $this->assertContains('organizations', $dependencies);
        $this->assertContains('users', $dependencies);
        $this->assertNotContains('materials', $dependencies);
        $this->assertNotContains('projects', $dependencies);
    }

    public function test_module_has_no_conflicts(): void
    {
        $conflicts = $this->module->getConflicts();

        $this->assertIsArray($conflicts);
        $this->assertEmpty($conflicts);
    }

    public function test_module_has_warehouse_permissions(): void
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
        $this->assertContains('warehouse.advanced.analytics', $permissions);
        $this->assertCount(16, $permissions);
    }

    public function test_module_has_features_list(): void
    {
        $features = $this->module->getFeatures();

        $this->assertIsArray($features);
        $this->assertNotEmpty($features);
        $this->assertCount(11, $features);
    }

    public function test_module_has_correct_limits(): void
    {
        $limits = $this->module->getLimits();

        $this->assertIsArray($limits);
        $this->assertEquals(20, $limits['max_warehouses']);
        $this->assertEquals(50, $limits['max_zones_per_warehouse']);
        $this->assertTrue($limits['barcode_support']);
        $this->assertTrue($limits['rfid_support']);
        $this->assertTrue($limits['batch_tracking']);
        $this->assertTrue($limits['serial_tracking']);
        $this->assertTrue($limits['auto_reorder']);
        $this->assertTrue($limits['analytics']);
        $this->assertEquals(-1, $limits['max_inventory_acts_per_month']);
    }

    public function test_module_can_activate_for_any_organization(): void
    {
        $this->assertTrue($this->module->canActivate(1));
    }

    public function test_module_has_default_settings(): void
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

    public function test_validate_settings_accepts_valid_settings(): void
    {
        $validSettings = [
            'low_stock_threshold' => 5,
            'cache_ttl' => 600,
        ];

        $this->assertTrue($this->module->validateSettings($validSettings));
    }

    public function test_validate_settings_rejects_invalid_threshold(): void
    {
        $invalidSettings = [
            'low_stock_threshold' => -1,
        ];

        $this->assertFalse($this->module->validateSettings($invalidSettings));
    }

    public function test_validate_settings_rejects_invalid_cache_ttl(): void
    {
        $invalidSettings = [
            'cache_ttl' => 30,
        ];

        $this->assertFalse($this->module->validateSettings($invalidSettings));
    }
}
