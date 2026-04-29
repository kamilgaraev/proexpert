<?php

declare(strict_types=1);

namespace Tests\Feature\Permissions;

use App\BusinessModules\Features\BasicWarehouse\BasicWarehouseModule;
use PHPUnit\Framework\TestCase;

class WarehousePermissionsTest extends TestCase
{
    public function test_basic_warehouse_has_unified_permissions(): void
    {
        $module = new BasicWarehouseModule();
        $permissions = $module->getPermissions();

        $this->assertCount(16, $permissions);
        $this->assertContains('warehouse.view', $permissions);
        $this->assertContains('warehouse.advanced.view', $permissions);
        $this->assertContains('warehouse.advanced.analytics', $permissions);
    }

    public function test_basic_warehouse_permissions_are_unique(): void
    {
        $module = new BasicWarehouseModule();
        $permissions = $module->getPermissions();

        $this->assertEquals(count($permissions), count(array_unique($permissions)));
    }

    public function test_warehouse_module_has_correct_billing_model(): void
    {
        $module = new BasicWarehouseModule();

        $this->assertEquals(\App\Enums\BillingModel::FREE, $module->getBillingModel());
    }

    public function test_role_definitions_include_warehouse_permissions(): void
    {
        $ownerRole = $this->roleDefinition('lk/organization_owner.json');

        $this->assertArrayHasKey('module_permissions', $ownerRole);
        $this->assertArrayHasKey('basic-warehouse', $ownerRole['module_permissions']);
        $this->assertArrayNotHasKey('advanced-warehouse', $ownerRole['module_permissions']);
    }

    public function test_admin_role_has_warehouse_permissions(): void
    {
        $adminRole = $this->roleDefinition('lk/organization_admin.json');

        $this->assertArrayHasKey('module_permissions', $adminRole);
        $this->assertArrayHasKey('basic-warehouse', $adminRole['module_permissions']);
        $this->assertArrayNotHasKey('advanced-warehouse', $adminRole['module_permissions']);

        $permissions = $adminRole['module_permissions']['basic-warehouse'];

        $this->assertCount(19, $permissions);
        $this->assertContains('warehouse.view', $permissions);
        $this->assertContains('warehouse.advanced.analytics', $permissions);
    }

    public function test_foreman_role_has_limited_warehouse_permissions(): void
    {
        $foremanRole = $this->roleDefinition('mobile/foreman.json');

        $this->assertArrayHasKey('module_permissions', $foremanRole);
        $this->assertArrayHasKey('basic-warehouse', $foremanRole['module_permissions']);
        $this->assertArrayNotHasKey('advanced-warehouse', $foremanRole['module_permissions']);

        $permissions = $foremanRole['module_permissions']['basic-warehouse'];

        $this->assertContains('warehouse.view', $permissions);
        $this->assertContains('warehouse.receipts', $permissions);
        $this->assertContains('warehouse.write_offs', $permissions);
        $this->assertContains('warehouse.transfers', $permissions);
        $this->assertContains('warehouse.advanced.barcode', $permissions);
        $this->assertCount(6, $permissions);
    }

    public function test_supplier_role_exists_and_has_warehouse_access(): void
    {
        $supplierRole = $this->roleDefinition('lk/supplier.json');

        $this->assertNotNull($supplierRole);
        $this->assertEquals('Снабженец', $supplierRole['name']);
        $this->assertEquals('supplier', $supplierRole['slug']);
        $this->assertArrayHasKey('basic-warehouse', $supplierRole['module_permissions']);
        $this->assertArrayNotHasKey('advanced-warehouse', $supplierRole['module_permissions']);

        $permissions = $supplierRole['module_permissions']['basic-warehouse'];

        $this->assertCount(16, $permissions);
        $this->assertContains('warehouse.advanced.multiple_warehouses', $permissions);
    }

    /**
     * @return array<string, mixed>
     */
    private function roleDefinition(string $path): array
    {
        $file = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config'
            . DIRECTORY_SEPARATOR . 'RoleDefinitions'
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

        return json_decode((string) file_get_contents($file), true);
    }
}
