<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\RegionalPriceStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceActivation;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\RegionalPriceActivationService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\RegionalPriceImportLifecycleService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\RegionalPriceQualityService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RegionalPriceImportLifecycleServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_worker_salary_does_not_activate_version_before_required_building_resources_arrive(): void
    {
        $quality = Mockery::mock(RegionalPriceQualityService::class);
        $quality->shouldNotReceive('checkCompleteVersion');
        $activation = Mockery::mock(RegionalPriceActivationService::class);
        $activation->shouldNotReceive('activate');
        $version = $this->version([
            'worker_salary_imported' => true,
            'building_resources_imported' => false,
        ]);

        $result = (new RegionalPriceImportLifecycleService($quality, $activation))
            ->finalize($version, true, true);

        self::assertFalse($result['ready']);
        self::assertFalse($result['activated']);
        self::assertSame(RegionalPriceStatus::CHECKED, $version->status);
        self::assertSame(['building_resources'], $version->metadata['import_lifecycle']['waiting_for']);
    }

    public function test_complete_quality_check_runs_before_single_activation(): void
    {
        $qualityResult = [
            'passed' => true,
            'metrics' => ['worker_salary' => [], 'building_resources' => []],
            'errors' => [],
        ];
        $quality = Mockery::mock(RegionalPriceQualityService::class);
        $quality->shouldReceive('checkCompleteVersion')->once()->withArgs(
            static fn (EstimateRegionalPriceVersion $version, bool $required): bool => $required
                && $version->metadata['worker_salary_imported']
                && $version->metadata['building_resources_imported']
        )->andReturn($qualityResult);
        $activationRecord = new EstimateRegionalPriceActivation;
        $activationRecord->setAttribute('id', 41);
        $activation = Mockery::mock(RegionalPriceActivationService::class);
        $activation->shouldReceive('activate')->once()->andReturn($activationRecord);
        $version = $this->version([
            'worker_salary_imported' => true,
            'building_resources_imported' => true,
        ]);

        $result = (new RegionalPriceImportLifecycleService($quality, $activation))
            ->finalize($version, true, true);

        self::assertTrue($result['ready']);
        self::assertTrue($result['activated']);
        self::assertSame(41, $result['activation_id']);
        self::assertTrue($version->metadata['complete_quality']['passed']);
    }

    public function test_activation_service_rejects_incomplete_version_before_database_activation(): void
    {
        $version = $this->version([
            'worker_salary_imported' => true,
            'building_resources_required' => true,
            'building_resources_imported' => false,
            'complete_quality' => ['passed' => false],
        ]);
        $version->setAttribute('status', RegionalPriceStatus::CHECKED->value);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not ready for activation');

        (new RegionalPriceActivationService)->activate($version);
    }

    /** @param array<string, mixed> $metadata */
    private function version(array $metadata): InMemoryRegionalPriceVersion
    {
        $version = new InMemoryRegionalPriceVersion;
        $version->setRawAttributes([
            'id' => 100,
            'status' => RegionalPriceStatus::PARSED->value,
            'errors_count' => 0,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ], true);

        return $version;
    }
}

class InMemoryRegionalPriceVersion extends EstimateRegionalPriceVersion
{
    public function update(array $attributes = [], array $options = []): bool
    {
        $this->fill($attributes);
        $this->syncChanges();

        return true;
    }

    public function refresh(): static
    {
        return $this;
    }
}
