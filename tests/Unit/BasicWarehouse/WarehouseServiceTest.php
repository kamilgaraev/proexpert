<?php

namespace Tests\Unit\BasicWarehouse;

use Tests\TestCase;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\BusinessModules\Features\BasicWarehouse\Contracts\WarehouseReportDataProvider;

class WarehouseServiceTest extends TestCase
{
    protected WarehouseService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WarehouseService::class);
    }

    public function test_service_implements_warehouse_report_data_provider()
    {
        $this->assertInstanceOf(WarehouseReportDataProvider::class, $this->service);
    }

    public function test_get_stock_data_returns_array()
    {
        $organizationId = 1;
        $filters = [];
        
        $data = $this->service->getStockData($organizationId, $filters);
        
        $this->assertIsArray($data);
    }

    public function test_get_movements_data_returns_array()
    {
        $organizationId = 1;
        $filters = [];
        
        $data = $this->service->getMovementsData($organizationId, $filters);
        
        $this->assertIsArray($data);
        // В текущей реализации возвращает заглушку
        $this->assertArrayHasKey('info', $data);
    }

    public function test_get_inventory_data_returns_array()
    {
        $organizationId = 1;
        $filters = [];
        
        $data = $this->service->getInventoryData($organizationId, $filters);
        
        $this->assertIsArray($data);
        // В текущей реализации возвращает заглушку
        $this->assertArrayHasKey('info', $data);
    }

    public function test_get_turnover_analytics_returns_error_for_basic_warehouse()
    {
        $organizationId = 1;
        $filters = [];
        
        $data = $this->service->getTurnoverAnalytics($organizationId, $filters);
        
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('AdvancedWarehouse', $data['error']);
    }

    public function test_get_forecast_data_returns_error_for_basic_warehouse()
    {
        $organizationId = 1;
        $filters = [];
        
        $data = $this->service->getForecastData($organizationId, $filters);
        
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('AdvancedWarehouse', $data['error']);
    }

    public function test_get_abc_xyz_analysis_returns_error_for_basic_warehouse()
    {
        $organizationId = 1;
        $filters = [];
        
        $data = $this->service->getAbcXyzAnalysis($organizationId, $filters);
        
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('AdvancedWarehouse', $data['error']);
    }
}

