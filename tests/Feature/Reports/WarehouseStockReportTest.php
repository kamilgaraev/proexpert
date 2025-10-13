<?php

namespace Tests\Feature\Reports;

use Tests\TestCase;
use App\Services\Report\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
}

