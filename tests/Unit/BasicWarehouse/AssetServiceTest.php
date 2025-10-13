<?php

namespace Tests\Unit\BasicWarehouse;

use Tests\TestCase;
use App\BusinessModules\Features\BasicWarehouse\Services\AssetService;
use App\BusinessModules\Features\BasicWarehouse\Models\Asset;

class AssetServiceTest extends TestCase
{
    protected AssetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AssetService::class);
    }

    public function test_get_asset_type_statistics_returns_array()
    {
        $organizationId = 1;
        
        $stats = $this->service->getAssetTypeStatistics($organizationId);
        
        $this->assertIsArray($stats);
    }

    public function test_get_asset_type_statistics_contains_all_asset_types()
    {
        $organizationId = 1;
        
        $stats = $this->service->getAssetTypeStatistics($organizationId);
        
        $assetTypes = Asset::getAssetTypes();
        
        foreach (array_keys($assetTypes) as $type) {
            $this->assertArrayHasKey($type, $stats);
            $this->assertArrayHasKey('label', $stats[$type]);
            $this->assertArrayHasKey('count', $stats[$type]);
            $this->assertArrayHasKey('total_value', $stats[$type]);
        }
    }

    public function test_import_assets_returns_result_array()
    {
        $organizationId = 1;
        $assetsData = [];
        
        $result = $this->service->importAssets($organizationId, $assetsData);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('created', $result);
        $this->assertArrayHasKey('updated', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertEquals(0, $result['created']);
        $this->assertEquals(0, $result['updated']);
        $this->assertIsArray($result['errors']);
    }
}

