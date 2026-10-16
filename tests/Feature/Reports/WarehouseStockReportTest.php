<?php

namespace Tests\Feature\Reports;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Report\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class WarehouseStockReportTest extends TestCase
{
    use RefreshDatabase;

    protected ReportService $reportService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reportService = app(ReportService::class);
    }

    public function test_generate_warehouse_stock_report_returns_array()
    {
        $organizationId = 1;
        $filters = [];
        
        $report = $this->reportService->generateWarehouseStockReport($organizationId, $filters);
        
        $this->assertIsArray($report);
        $this->assertArrayHasKey('title', $report);
        $this->assertArrayHasKey('data', $report);
        $this->assertArrayHasKey('filters', $report);
        $this->assertArrayHasKey('generated_at', $report);
    }

    public function test_warehouse_stock_report_has_correct_title()
    {
        $organizationId = 1;
        $filters = [];
        
        $report = $this->reportService->generateWarehouseStockReport($organizationId, $filters);
        
        $this->assertEquals('Отчет по остаткам на складе', $report['title']);
    }

    public function test_warehouse_stock_report_data_is_array()
    {
        $organizationId = 1;
        $filters = [];
        
        $report = $this->reportService->generateWarehouseStockReport($organizationId, $filters);
        
        $this->assertIsArray($report['data']);
    }

    public function test_warehouse_stock_report_respects_filters()
    {
        $organizationId = 1;
        $filters = [
            'warehouse_id' => 1,
            'asset_type' => 'material',
            'low_stock' => true,
        ];
        
        $report = $this->reportService->generateWarehouseStockReport($organizationId, $filters);
        
        $this->assertEquals($filters, $report['filters']);
    }

    public function test_get_warehouse_stock_report_includes_stock_assets_of_all_types(): void
    {
        [$organization, $warehouse, $material, $tool, $user] = $this->createStockReportContext();

        $this->createBalance((int) $organization->id, (int) $warehouse->id, (int) $material->id, 12);
        $this->createBalance((int) $organization->id, (int) $warehouse->id, (int) $tool->id, 3);

        $report = $this->reportService->getWarehouseStockReport(
            $this->makeWarehouseStockRequest($organization, $user)
        );

        $this->assertEqualsCanonicalizing(['Cement M500', 'Screwdriver'], $report['data']->pluck('material_name')->all());
        $this->assertArrayNotHasKey('asset_type', $report['filters']);
    }

    public function test_get_warehouse_stock_report_can_filter_tool_assets_explicitly(): void
    {
        [$organization, $warehouse, $material, $tool, $user] = $this->createStockReportContext();

        $this->createBalance((int) $organization->id, (int) $warehouse->id, (int) $material->id, 12);
        $this->createBalance((int) $organization->id, (int) $warehouse->id, (int) $tool->id, 3);

        $report = $this->reportService->getWarehouseStockReport(
            $this->makeWarehouseStockRequest($organization, $user, ['asset_type' => 'tool'])
        );

        $this->assertSame(['Screwdriver'], $report['data']->pluck('material_name')->all());
        $this->assertSame('tool', $report['filters']['asset_type']);
    }

    public function test_warehouse_quantity_totals_keep_distinct_units_and_do_not_sum_unknown_units(): void
    {
        [$organization, $warehouse, $material, $tool, $user] = $this->createStockReportContext();
        $kilograms = MeasurementUnit::query()->firstOrCreate(['organization_id' => $organization->id, 'short_name' => 'кг'], ['name' => 'Килограмм', 'type' => 'work', 'is_system' => false]);
        $pieces = MeasurementUnit::query()->firstOrCreate(['organization_id' => $organization->id, 'short_name' => 'шт'], ['name' => 'Штука', 'type' => 'work', 'is_system' => false]);
        $material->update(['measurement_unit_id' => $kilograms->id]);
        $tool->update(['measurement_unit_id' => $pieces->id]);
        $unknown = Material::query()->create(['organization_id' => $organization->id, 'name' => 'Без единицы', 'code' => 'UNKNOWN', 'is_active' => true]);
        $extra = Material::query()->create(['organization_id' => $organization->id, 'name' => 'Цемент дополнительный', 'code' => 'EXTRA', 'measurement_unit_id' => $kilograms->id, 'is_active' => true]);
        foreach ([[$material, 12], [$tool, 3], [$unknown, 2], [$extra, 5.25]] as [$asset, $quantity]) {
            $this->createBalance((int) $organization->id, (int) $warehouse->id, (int) $asset->id, $quantity);
        }
        $report = $this->reportService->getWarehouseStockReport($this->makeWarehouseStockRequest($organization, $user));
        $this->assertEqualsCanonicalizing([
            ['unit_id' => $kilograms->id, 'unit' => 'кг', 'quantity' => 17.25],
            ['unit_id' => $pieces->id, 'unit' => 'шт', 'quantity' => 3.0],
            ['unit_id' => null, 'unit' => null, 'quantity' => null],
        ], $report['totals']['quantity_by_unit']);
        $this->assertSame(2225.0, $report['totals']['total_value']);
        $html = view('reports.warehouse-stock-pdf', ['data' => $report['data'], 'totals' => $report['totals'], 'generated_at' => '16.10.2026 00:00'])->render();
        $this->assertStringContainsString('17,25 кг', $html);
        $this->assertStringContainsString('3,00 шт', $html);
        $this->assertStringNotContainsString('22,25', $html);
    }

    public function test_get_warehouse_stock_report_excludes_zero_balance_assets_without_movements(): void
    {
        [$organization, $warehouse, $material, $tool, $user] = $this->createStockReportContext();
        $bareAsset = Material::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Bare drill',
            'code' => 'TOOL-EMPTY',
            'additional_properties' => ['asset_type' => 'tool'],
            'is_active' => true,
        ]);

        $this->createBalance((int) $organization->id, (int) $warehouse->id, (int) $material->id, 12);
        $this->createBalance((int) $organization->id, (int) $warehouse->id, (int) $tool->id, 0);
        $this->createMovement((int) $organization->id, (int) $warehouse->id, (int) $tool->id, 3);
        $this->createBalance((int) $organization->id, (int) $warehouse->id, (int) $bareAsset->id, 0);

        $report = $this->reportService->getWarehouseStockReport(
            $this->makeWarehouseStockRequest($organization, $user)
        );

        $this->assertEqualsCanonicalizing(['Cement M500', 'Screwdriver'], $report['data']->pluck('material_name')->all());
    }

    public function test_report_uses_current_readable_custody_warehouse_name_instead_of_technical_login(): void
    {
        [$organization, $warehouse, $material, $tool, $user] = $this->createStockReportContext();
        $organization->users()->attach($user->id, ['is_owner' => false, 'is_active' => true]);
        $user->update(['name' => 'technical_stock_login']);
        $project = \App\Models\Project::factory()->create(['organization_id' => $organization->id, 'name' => 'Объект приёмки']);
        $warehouse->update(['warehouse_type' => OrganizationWarehouse::TYPE_CUSTODY, 'project_id' => $project->id,
            'responsible_user_id' => $user->id, 'name' => 'Ответственное хранение: Старый проект, technical_stock_login']);
        $this->createBalance((int) $organization->id, (int) $warehouse->id, (int) $material->id, 12);
        $report = $this->reportService->getWarehouseStockReport($this->makeWarehouseStockRequest($organization, $user));
        $this->assertSame(['Ответственное хранение: Объект приёмки, ФИО не указано'], $report['data']->pluck('warehouse')->all());
        $html = view('reports.warehouse-stock-pdf', ['data' => $report['data'], 'totals' => $report['totals'], 'generated_at' => '16.10.2026 00:00'])->render();
        $this->assertStringNotContainsString('technical_stock_login', $html);
        $this->assertStringContainsString('ФИО не указано', $html);
    }

    private function createStockReportContext(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Main warehouse',
            'code' => 'MAIN',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
        $material = Material::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Cement M500',
            'code' => 'CEM-500',
            'additional_properties' => ['asset_type' => 'material'],
            'is_active' => true,
        ]);
        $tool = Material::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Screwdriver',
            'code' => 'TOOL-001',
            'additional_properties' => ['asset_type' => 'tool'],
            'is_active' => true,
        ]);

        return [$organization, $warehouse, $material, $tool, $user];
    }

    private function createBalance(int $organizationId, int $warehouseId, int $materialId, float $quantity): WarehouseBalance
    {
        return WarehouseBalance::query()->create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'available_quantity' => $quantity,
            'reserved_quantity' => 0,
            'unit_price' => 100,
            'min_stock_level' => 0,
            'max_stock_level' => 0,
        ]);
    }

    private function createMovement(int $organizationId, int $warehouseId, int $materialId, float $quantity): WarehouseMovement
    {
        return WarehouseMovement::query()->create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'movement_type' => WarehouseMovement::TYPE_WRITE_OFF,
            'quantity' => $quantity,
            'price' => 100,
            'document_number' => 'TEST-' . $materialId,
            'movement_date' => now(),
        ]);
    }

    private function makeWarehouseStockRequest(Organization $organization, User $user, array $query = []): Request
    {
        $request = Request::create('/api/v1/admin/reports/warehouse-stock', 'GET', $query);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('current_organization_id', $organization->id);

        return $request;
    }
}
