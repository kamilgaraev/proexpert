<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsBuildingResourcePriceUpdateService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsRegionalPriceSynchronizationException;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsRegionalPriceSynchronizationService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsRegionalPriceUpdateService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

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

        $this->expectException(FgiscsRegionalPriceSynchronizationException::class);
        $this->expectExceptionMessage(FgiscsRegionalPriceSynchronizationException::WORKER_COMPONENT_FAILED);

        (new FgiscsRegionalPriceSynchronizationService($workerSalary, $buildingResources))
            ->syncTatarstan('prices', 77);
    }

    public function test_mismatched_skipped_component_revisions_report_safe_final_state(): void
    {
        $workerSalary = Mockery::mock(FgiscsRegionalPriceUpdateService::class);
        $workerSalary->shouldReceive('syncTatarstan')
            ->twice()
            ->andReturn(
                [
                    'skipped' => true,
                    'status' => 'active',
                    'version_id' => 10,
                ],
                [
                    'skipped' => true,
                    'status' => 'checked',
                    'version_id' => 11,
                ],
            );
        $buildingResources = Mockery::mock(FgiscsBuildingResourcePriceUpdateService::class);
        $buildingResources->shouldReceive('syncTatarstan')->once()->andReturn([
            'skipped' => true,
            'status' => 'checked',
            'version_id' => 11,
        ]);

        try {
            (new FgiscsRegionalPriceSynchronizationService($workerSalary, $buildingResources))
                ->syncTatarstan('prices', 77);
            self::fail('Synchronization mismatch must fail.');
        } catch (FgiscsRegionalPriceSynchronizationException $exception) {
            self::assertSame(FgiscsRegionalPriceSynchronizationException::FINAL_VERSION_NOT_ACTIVE, $exception->failureCode);
            self::assertSame([
                'failure_code' => 'final_version_not_active',
                'worker_status' => 'active',
                'worker_version_id' => 10,
                'building_status' => 'checked',
                'building_version_id' => 11,
                'final_status' => 'checked',
                'final_version_id' => 11,
            ], $exception->safeContext());
        }
    }
}
