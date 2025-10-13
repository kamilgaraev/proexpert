<?php

namespace Tests\Feature\Reports;

use Tests\TestCase;
use App\Services\Report\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WarehouseInventoryReportTest extends TestCase
{
    use RefreshDatabase;

    protected ReportService $reportService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reportService = app(ReportService::class);
    }

    public function test_generate_warehouse_inventory_report_returns_array()
    {
        $organizationId = 1;
        $filters = [];
        
        $report = $this->reportService->generateWarehouseInventoryReport($organizationId, $filters);
        
        $this->assertIsArray($report);
        $this->assertArrayHasKey('title', $report);
        $this->assertArrayHasKey('data', $report);
        $this->assertArrayHasKey('filters', $report);
        $this->assertArrayHasKey('generated_at', $report);
    }

    public function test_warehouse_inventory_report_has_correct_title()
    {
        $organizationId = 1;
        $filters = [];
        
        $report = $this->reportService->generateWarehouseInventoryReport($organizationId, $filters);
        
        $this->assertEquals('Отчет инвентаризации', $report['title']);
    }

    public function test_warehouse_inventory_report_data_is_array()
    {
        $organizationId = 1;
        $filters = [];
        
        $report = $this->reportService->generateWarehouseInventoryReport($organizationId, $filters);
        
        $this->assertIsArray($report['data']);
    }

    public function test_warehouse_inventory_report_respects_status_filter()
    {
        $organizationId = 1;
        $filters = [
            'warehouse_id' => 1,
            'status' => 'completed',
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
        ];
        
        $report = $this->reportService->generateWarehouseInventoryReport($organizationId, $filters);
        
        $this->assertEquals($filters, $report['filters']);
    }

    public function test_basic_warehouse_integration_with_basic_reports()
    {
        // Проверяем что BasicWarehouse корректно интегрируется с BasicReports
        $organizationId = 1;
        
        $stockReport = $this->reportService->generateWarehouseStockReport($organizationId);
        $movementsReport = $this->reportService->generateWarehouseMovementsReport($organizationId);
        $inventoryReport = $this->reportService->generateWarehouseInventoryReport($organizationId);
        
        // Все отчеты должны возвращать корректную структуру
        $this->assertArrayHasKey('title', $stockReport);
        $this->assertArrayHasKey('title', $movementsReport);
        $this->assertArrayHasKey('title', $inventoryReport);
    }

    public function test_advanced_warehouse_analytics_require_advanced_reports()
    {
        $organizationId = 1;
        
        // Аналитика должна возвращать заглушки для BasicWarehouse
        $turnoverReport = $this->reportService->generateWarehouseTurnoverAnalytics($organizationId);
        $forecastReport = $this->reportService->generateWarehouseForecastReport($organizationId);
        $abcXyzReport = $this->reportService->generateWarehouseAbcXyzAnalysis($organizationId);
        
        // Проверяем что заглушки корректны
        $this->assertArrayHasKey('title', $turnoverReport);
        $this->assertArrayHasKey('title', $forecastReport);
        $this->assertArrayHasKey('title', $abcXyzReport);
    }
}

