<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsBuildingResourcePriceUpdateService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsRegionalPriceSynchronizationService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsRegionalPriceUpdateService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FgiscsRegionalPriceSynchronizationServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_active_incomplete_base_is_left_untouched_and_revision_is_completed_in_one_cycle(): void
    {
        $workerSalary = Mockery::mock(FgiscsRegionalPriceUpdateService::class);
        $workerSalary->shouldReceive('syncTatarstan')
            ->once()
            ->with('prices', 77, true, false, null)
            ->ordered()
            ->andReturn([
                'skipped' => true,
                'status' => 'active',
                'version_id' => 10,
                'version_key' => '2026-q2-ru-ta',
            ]);
        $buildingResources = Mockery::mock(FgiscsBuildingResourcePriceUpdateService::class);
        $buildingResources->shouldReceive('syncTatarstan')
            ->once()
            ->with('prices', 77, false, true, null)
            ->ordered()
            ->andReturn([
                'status' => 'parsed',
                'version_id' => 11,
                'version_key' => '2026-q2-ru-ta-r1',
            ]);
        $workerSalary->shouldReceive('syncTatarstan')
            ->once()
            ->with('prices', 77, true, false, null)
            ->ordered()
            ->andReturn([
                'status' => 'active',
                'version_id' => 11,
                'version_key' => '2026-q2-ru-ta-r1',
            ]);

        $result = (new FgiscsRegionalPriceSynchronizationService($workerSalary, $buildingResources))
            ->syncTatarstan('prices', 77);

        self::assertSame('active', $result['status']);
        self::assertSame(11, $result['version_id']);
        self::assertSame('2026-q2-ru-ta-r1', $result['version_key']);
        self::assertSame(10, $result['worker_salary_result']['version_id']);
    }

    public function test_failed_worker_import_is_not_reported_as_success_and_building_import_does_not_start(): void
    {
        $workerSalary = Mockery::mock(FgiscsRegionalPriceUpdateService::class);
        $workerSalary->shouldReceive('syncTatarstan')->once()->andReturn([
            'status' => 'failed',
            'version_id' => 12,
        ]);
        $buildingResources = Mockery::mock(FgiscsBuildingResourcePriceUpdateService::class);
        $buildingResources->shouldNotReceive('syncTatarstan');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('worker_salary');

        (new FgiscsRegionalPriceSynchronizationService($workerSalary, $buildingResources))
            ->syncTatarstan('prices', 77);
    }
}
