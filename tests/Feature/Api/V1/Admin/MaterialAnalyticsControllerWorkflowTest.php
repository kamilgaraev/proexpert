<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class MaterialAnalyticsControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_material_analytics_reads_current_warehouse_balances_and_movements(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activateMaterialAnalyticsModule($context->organization->id);
        $warehouseId = $this->createWarehouse($context->organization->id);
        $unitId = $this->createMeasurementUnit($context->organization->id, 'шт');
        $materialId = $this->createMaterial($context->organization->id, $unitId, 'Кабель ВВГ', 'CBL-1');

        DB::table('warehouse_balances')->insert([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'available_quantity' => 8,
            'reserved_quantity' => 2,
            'unit_price' => 120,
            'min_stock_level' => 10,
            'last_movement_at' => now(),
        ]);

        DB::table('warehouse_movements')->insert([
            [
                'organization_id' => $context->organization->id,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'movement_type' => 'receipt',
                'quantity' => 15,
                'price' => 120,
                'project_id' => null,
                'movement_date' => now()->subDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'organization_id' => $context->organization->id,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'movement_type' => 'write_off',
                'quantity' => 5,
                'price' => 120,
                'project_id' => null,
                'movement_date' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $summaryResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/materials/analytics/summary');
        $usageResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/materials/analytics/usage-by-projects');
        $lowStockResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/materials/analytics/low-stock?threshold=10');

        $summaryResponse->assertOk();
        $summaryResponse->assertJsonPath('success', true);
        $summaryResponse->assertJsonPath('data.total_materials_count', 1);
        $summaryResponse->assertJsonPath('data.total_inventory_value', 960);
        $summaryResponse->assertJsonPath('data.low_stock_count', 1);
        $summaryResponse->assertJsonPath('data.recent_movements_count', 2);

        $usageResponse->assertOk();
        $usageResponse->assertJsonPath('data.0.project_id', 0);
        $usageResponse->assertJsonPath('data.0.project_name', 'Без проекта');
        $usageResponse->assertJsonPath('data.0.total_quantity', 5);
        $usageResponse->assertJsonPath('data.0.total_cost', 600);

        $lowStockResponse->assertOk();
        $lowStockResponse->assertJsonPath('data.0.id', $materialId);
        $lowStockResponse->assertJsonPath('data.0.name', 'Кабель ВВГ');
        $lowStockResponse->assertJsonPath('data.0.sku', 'CBL-1');
        $lowStockResponse->assertJsonPath('data.0.current_stock', 8);
        $lowStockResponse->assertJsonPath('data.0.minimum_stock', 10);
    }

    private function createWarehouse(int $organizationId): int
    {
        return (int) DB::table('organization_warehouses')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Основной склад',
            'code' => 'MAIN',
            'warehouse_type' => 'central',
            'is_main' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createMeasurementUnit(int $organizationId, string $shortName): int
    {
        $existingId = DB::table('measurement_units')
            ->where('organization_id', $organizationId)
            ->where('short_name', $shortName)
            ->value('id');

        if ($existingId !== null) {
            return (int) $existingId;
        }

        return (int) DB::table('measurement_units')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Штука',
            'short_name' => $shortName,
            'type' => 'material',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createMaterial(int $organizationId, int $unitId, string $name, string $code): int
    {
        return (int) DB::table('materials')->insertGetId([
            'organization_id' => $organizationId,
            'name' => $name,
            'code' => $code,
            'measurement_unit_id' => $unitId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function activateMaterialAnalyticsModule(int $organizationId): void
    {
        $module = Module::query()->firstOrCreate(
            ['slug' => 'material-analytics'],
            [
                'name' => 'Аналитика материалов',
                'version' => '1.0.0',
                'type' => 'addon',
                'billing_model' => 'subscription',
                'category' => 'warehouse',
                'permissions' => [
                    'materials.analytics.summary',
                    'materials.analytics.usage_by_projects',
                    'materials.analytics.low_stock',
                ],
                'is_active' => true,
                'is_system_module' => false,
            ]
        );

        OrganizationModuleActivation::query()->create([
            'organization_id' => $organizationId,
            'module_id' => $module->id,
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }
}
