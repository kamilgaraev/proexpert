<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateSelectionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class NormativeCandidateSelectionServiceTest extends TestCase
{
    public function test_rejects_unoffered_norm_by_default(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->assertCandidateFor([
            'key' => 'work-1',
            'normative_candidates' => [],
        ], 101);
    }

    public function test_allows_unoffered_norm_when_catalog_selection_is_explicit(): void
    {
        $this->service()->assertCandidateFor([
            'key' => 'work-1',
            'normative_candidates' => [],
        ], 101, true);

        self::assertTrue(true);
    }

    public function test_rejects_norm_selection_for_unconfirmed_drawing_quantity(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->assertSelectable([
            'key' => 'rough.walls',
            'item_type' => 'quantity_review',
        ]);
    }

    private function service(): TestableNormativeCandidateSelectionService
    {
        return new TestableNormativeCandidateSelectionService(
            $this->createMock(EstimateNormativeMatcher::class),
            $this->createMock(ResourceAssemblyService::class),
            $this->createMock(EstimatePricingService::class),
            $this->createMock(EstimateValidationService::class),
            $this->createMock(EstimateGenerationPackagePersistenceService::class),
            (new \ReflectionClass(EstimateGenerationLearningRecorder::class))->newInstanceWithoutConstructor(),
        );
    }
}

final class TestableNormativeCandidateSelectionService extends NormativeCandidateSelectionService
{
    /**
     * @param array<string, mixed> $workItem
     */
    public function assertCandidateFor(array $workItem, int $normId, bool $allowCatalogSelection = false): void
    {
        $this->assertCandidateWasOffered($workItem, $normId, $allowCatalogSelection);
    }

    /**
     * @param array<string, mixed> $workItem
     */
    public function assertSelectable(array $workItem): void
    {
        $this->assertWorkItemCanSelectNorm($workItem);
    }

    protected function message(string $key): string
    {
        return $key;
    }

    protected function validationException(array $messages): ValidationException
    {
        return new class($messages) extends ValidationException {
            /**
             * @param array<string, array<int, string>> $messages
             */
            public function __construct(private readonly array $messages)
            {
            }

            public function errors()
            {
                return $this->messages;
            }
        };
    }
}
