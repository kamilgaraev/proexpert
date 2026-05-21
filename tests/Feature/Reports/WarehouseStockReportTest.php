<?php

namespace Tests\Feature\Reports;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\Models\Material;
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

    public function test_get_warehouse_stock_report_defaults_to_material_assets_only(): void
    {
        [$organization, $warehouse, $material, $tool, $user] = $this->createStockReportContext();

        $this->createBalance((int) $organization->id, (int) $warehouse->id, (int) $material->id, 12);
        $this->createBalance((int) $organization->id, (int) $warehouse->id, (int) $tool->id, 3);

        $report = $this->reportService->getWarehouseStockReport(
            $this->makeWarehouseStockRequest($organization, $user)
        );

        $this->assertSame(['Cement M500'], $report['data']->pluck('material_name')->all());
        $this->assertSame('material', $report['filters']['asset_type']);
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

    private function makeWarehouseStockRequest(Organization $organization, User $user, array $query = []): Request
    {
        $request = Request::create('/api/v1/admin/reports/warehouse-stock', 'GET', $query);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('current_organization_id', $organization->id);

        return $request;
    }
}

