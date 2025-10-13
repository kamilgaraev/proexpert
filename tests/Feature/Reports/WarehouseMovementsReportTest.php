<?php

namespace Tests\Feature\Reports;

use Tests\TestCase;
use App\Services\Report\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WarehouseMovementsReportTest extends TestCase
{
    use RefreshDatabase;

    protected ReportService $reportService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reportService = app(ReportService::class);
    }

    public function test_generate_warehouse_movements_report_returns_array()
    {
        $organizationId = 1;
        $filters = [];
        
        $report = $this->reportService->generateWarehouseMovementsReport($organizationId, $filters);
        
        $this->assertIsArray($report);
        $this->assertArrayHasKey('title', $report);
        $this->assertArrayHasKey('data', $report);
        $this->assertArrayHasKey('filters', $report);
        $this->assertArrayHasKey('generated_at', $report);
    }

    public function test_warehouse_movements_report_has_correct_title()
    {
        $organizationId = 1;
        $filters = [];
        
        $report = $this->reportService->generateWarehouseMovementsReport($organizationId, $filters);
        
        $this->assertEquals('Отчет по движению активов', $report['title']);
    }

    public function test_warehouse_movements_report_data_is_array()
    {
        $organizationId = 1;
        $filters = [];
        
        $report = $this->reportService->generateWarehouseMovementsReport($organizationId, $filters);
        
        $this->assertIsArray($report['data']);
    }

    public function test_warehouse_movements_report_respects_date_filters()
    {
        $organizationId = 1;
        $filters = [
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
            'warehouse_id' => 1,
            'movement_type' => 'receipt',
        ];
        
        $report = $this->reportService->generateWarehouseMovementsReport($organizationId, $filters);
        
        $this->assertEquals($filters, $report['filters']);
    }
}

