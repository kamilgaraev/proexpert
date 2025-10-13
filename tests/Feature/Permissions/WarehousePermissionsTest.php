<?php

namespace Tests\Feature\Permissions;

use Tests\TestCase;
use App\BusinessModules\Features\BasicWarehouse\BasicWarehouseModule;
use App\BusinessModules\Features\AdvancedWarehouse\AdvancedWarehouseModule;
use App\BusinessModules\Features\BasicReports\BasicReportsModule;
use App\BusinessModules\Features\AdvancedReports\AdvancedReportsModule;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WarehousePermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_basic_warehouse_has_correct_permissions_count()
    {
        $module = new BasicWarehouseModule();
        $permissions = $module->getPermissions();
        
        $this->assertCount(7, $permissions);
    }

    public function test_advanced_warehouse_has_correct_permissions_count()
    {
        $module = new AdvancedWarehouseModule();
        $permissions = $module->getPermissions();
        
        $this->assertCount(14, $permissions);
    }

    public function test_basic_reports_includes_warehouse_permissions()
    {
        $module = new BasicReportsModule();
        $permissions = $module->getPermissions();
        
        $this->assertContains('basic_reports.warehouse_stock', $permissions);
        $this->assertContains('basic_reports.warehouse_movements', $permissions);
        $this->assertContains('basic_reports.warehouse_inventory', $permissions);
    }

    public function test_advanced_reports_includes_warehouse_analytics_permissions()
    {
        $module = new AdvancedReportsModule();
        $permissions = $module->getPermissions();
        
        $this->assertContains('advanced_reports.warehouse_analytics', $permissions);
        $this->assertContains('advanced_reports.warehouse_forecasts', $permissions);
        $this->assertContains('advanced_reports.warehouse_turnover', $permissions);
        $this->assertContains('advanced_reports.warehouse_abc_analysis', $permissions);
    }

    public function test_basic_warehouse_permissions_are_unique()
    {
        $module = new BasicWarehouseModule();
        $permissions = $module->getPermissions();
        
        $uniquePermissions = array_unique($permissions);
        
        $this->assertEquals(count($permissions), count($uniquePermissions));
    }

    public function test_advanced_warehouse_permissions_are_unique()
    {
        $module = new AdvancedWarehouseModule();
        $permissions = $module->getPermissions();
        
        $uniquePermissions = array_unique($permissions);
        
        $this->assertEquals(count($permissions), count($uniquePermissions));
    }

    public function test_basic_and_advanced_warehouse_permissions_dont_overlap()
    {
        $basicModule = new BasicWarehouseModule();
        $advancedModule = new AdvancedWarehouseModule();
        
        $basicPermissions = $basicModule->getPermissions();
        $advancedPermissions = $advancedModule->getPermissions();
        
        $overlap = array_intersect($basicPermissions, $advancedPermissions);
        
        // Не должно быть пересечений, т.к. используются разные префиксы
        $this->assertEmpty($overlap);
    }

    public function test_warehouse_modules_have_correct_billing_models()
    {
        $basicModule = new BasicWarehouseModule();
        $advancedModule = new AdvancedWarehouseModule();
        
        $this->assertEquals(\App\Enums\BillingModel::FREE, $basicModule->getBillingModel());
        $this->assertEquals(\App\Enums\BillingModel::SUBSCRIPTION, $advancedModule->getBillingModel());
    }

    public function test_advanced_warehouse_depends_on_basic_warehouse()
    {
        $advancedModule = new AdvancedWarehouseModule();
        $dependencies = $advancedModule->getDependencies();
        
        $this->assertContains('basic-warehouse', $dependencies);
    }

    public function test_advanced_reports_depends_on_basic_reports()
    {
        $advancedModule = new AdvancedReportsModule();
        $dependencies = $advancedModule->getDependencies();
        
        $this->assertContains('basic-reports', $dependencies);
    }

    public function test_role_definitions_include_warehouse_permissions()
    {
        // Проверяем что RoleDefinitions обновлены
        $ownerRole = json_decode(file_get_contents(config_path('RoleDefinitions/lk/organization_owner.json')), true);
        
        $this->assertArrayHasKey('module_permissions', $ownerRole);
        $this->assertArrayHasKey('basic-warehouse', $ownerRole['module_permissions']);
        $this->assertArrayHasKey('advanced-warehouse', $ownerRole['module_permissions']);
    }

    public function test_admin_role_has_warehouse_permissions()
    {
        $adminRole = json_decode(file_get_contents(config_path('RoleDefinitions/lk/organization_admin.json')), true);
        
        $this->assertArrayHasKey('module_permissions', $adminRole);
        $this->assertArrayHasKey('basic-warehouse', $adminRole['module_permissions']);
        $this->assertArrayHasKey('advanced-warehouse', $adminRole['module_permissions']);
        
        // Admin должен иметь все права обоих модулей
        $basicPermissions = $adminRole['module_permissions']['basic-warehouse'];
        $this->assertCount(7, $basicPermissions);
        
        $advancedPermissions = $adminRole['module_permissions']['advanced-warehouse'];
        $this->assertCount(14, $advancedPermissions);
    }

    public function test_foreman_role_has_limited_warehouse_permissions()
    {
        $foremanRole = json_decode(file_get_contents(config_path('RoleDefinitions/mobile/foreman.json')), true);
        
        $this->assertArrayHasKey('module_permissions', $foremanRole);
        $this->assertArrayHasKey('basic-warehouse', $foremanRole['module_permissions']);
        
        // Foreman должен иметь только базовые права
        $permissions = $foremanRole['module_permissions']['basic-warehouse'];
        $this->assertContains('warehouse.view', $permissions);
        $this->assertContains('warehouse.receipts', $permissions);
        $this->assertContains('warehouse.write_offs', $permissions);
        $this->assertCount(3, $permissions);
        
        // Foreman НЕ должен иметь доступа к advanced-warehouse
        $this->assertArrayNotHasKey('advanced-warehouse', $foremanRole['module_permissions']);
    }

    public function test_supplier_role_exists_and_has_warehouse_access()
    {
        $supplierRole = json_decode(file_get_contents(config_path('RoleDefinitions/lk/supplier.json')), true);
        
        $this->assertNotNull($supplierRole);
        $this->assertEquals('Снабженец', $supplierRole['name']);
        $this->assertEquals('supplier', $supplierRole['slug']);
        
        // Supplier должен иметь полный доступ к складу
        $this->assertArrayHasKey('basic-warehouse', $supplierRole['module_permissions']);
        $this->assertArrayHasKey('advanced-warehouse', $supplierRole['module_permissions']);
        
        $basicPermissions = $supplierRole['module_permissions']['basic-warehouse'];
        $this->assertCount(7, $basicPermissions);
    }
}

