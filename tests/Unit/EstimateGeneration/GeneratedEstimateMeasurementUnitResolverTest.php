<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\GeneratedEstimateNumberAllocator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\LaravelGeneratedEstimateWriter;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDraftPersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationFinalWorkItemGuard;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationReviewItemService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GeneratedEstimateMeasurementUnitResolverTest extends TestCase
{
    #[Test]
    public function latin_ai_units_resolve_to_existing_russian_measurement_units(): void
    {
        $writer = new TestMeasurementUnitGeneratedEstimateWriter(
            $this->draftService(),
            $this->createMock(GeneratedEstimateNumberAllocator::class),
            [
                ['id' => 11, 'organization_id' => null, 'name' => 'Квадратный метр', 'short_name' => 'м²'],
                ['id' => 12, 'organization_id' => null, 'name' => 'Кубический метр', 'short_name' => 'м³'],
                ['id' => 13, 'organization_id' => null, 'name' => 'Метр', 'short_name' => 'м'],
                ['id' => 14, 'organization_id' => null, 'name' => 'Килограмм', 'short_name' => 'кг'],
                ['id' => 15, 'organization_id' => null, 'name' => 'Штука', 'short_name' => 'шт'],
            ],
        );

        self::assertSame(11, $writer->resolveForTest('m2'));
        self::assertSame(12, $writer->resolveForTest('m3'));
        self::assertSame(13, $writer->resolveForTest('m'));
        self::assertSame(14, $writer->resolveForTest('kg'));
        self::assertSame(15, $writer->resolveForTest('pcs'));
    }

    #[Test]
    public function unknown_unit_is_not_silently_mapped_to_a_piece(): void
    {
        $writer = new TestMeasurementUnitGeneratedEstimateWriter(
            $this->draftService(),
            $this->createMock(GeneratedEstimateNumberAllocator::class),
            [['id' => 15, 'organization_id' => null, 'name' => 'Штука', 'short_name' => 'шт']],
        );

        self::assertNull($writer->resolveForTest('неизвестная единица'));
    }

    private function draftService(): EstimateDraftPersistenceService
    {
        return new EstimateDraftPersistenceService(
            new EstimateGenerationFinalWorkItemGuard,
            new EstimateGenerationReviewItemService(new EstimateGenerationPackagePresenter),
        );
    }
}

final class TestMeasurementUnitGeneratedEstimateWriter extends LaravelGeneratedEstimateWriter
{
    /** @param list<array{id: int, organization_id: int|null, name: string, short_name: string}> $units */
    public function __construct(
        EstimateDraftPersistenceService $draftService,
        GeneratedEstimateNumberAllocator $numberAllocator,
        private readonly array $units,
    ) {
        parent::__construct($draftService, $numberAllocator);
    }

    public function resolveForTest(string $unit): ?int
    {
        return $this->resolveMeasurementUnitId(75, $unit);
    }

    protected function measurementUnits(int $organizationId): array
    {
        return $this->units;
    }
}
