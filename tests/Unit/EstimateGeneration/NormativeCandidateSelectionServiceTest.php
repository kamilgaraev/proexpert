<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
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

    public function test_draft_still_requires_review_when_quantities_are_unconfirmed(): void
    {
        self::assertTrue($this->service()->draftNeedsReview([
            'quality_summary' => [
                'normative_items' => ['requires_review' => 0],
                'quantity_review_work_items' => 1,
                'not_calculated_work_items' => 0,
                'safe_norm_required_work_items' => 0,
                'duplicate_work_items' => 0,
            ],
        ]));
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
            (new \ReflectionClass(AdvanceEstimateGeneration::class))->newInstanceWithoutConstructor(),
        );
    }
}

final class TestableNormativeCandidateSelectionService extends NormativeCandidateSelectionService
{
    /**
     * @param  array<string, mixed>  $workItem
     */
    public function assertCandidateFor(array $workItem, int $normId, bool $allowCatalogSelection = false): void
    {
        $this->assertCandidateWasOffered($workItem, $normId, $allowCatalogSelection);
    }

    /**
     * @param  array<string, mixed>  $workItem
     */
    public function assertSelectable(array $workItem): void
    {
        $this->assertWorkItemCanSelectNorm($workItem);
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    public function draftNeedsReview(array $draft): bool
    {
        $method = new \ReflectionMethod(NormativeCandidateSelectionService::class, 'draftRequiresReview');
        $method->setAccessible(true);

        return (bool) $method->invoke($this, $draft);
    }

    protected function message(string $key): string
    {
        return $key;
    }

    protected function validationException(array $messages): ValidationException
    {
        return new class($messages) extends ValidationException
        {
            /**
             * @param  array<string, array<int, string>>  $messages
             */
            public function __construct(private readonly array $messages) {}

            public function errors()
            {
                return $this->messages;
            }
        };
    }
}
