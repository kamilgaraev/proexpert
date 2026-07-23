<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateSelectionHardGate;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateSelectionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeScopeRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeSearchProfileCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeSemanticCompatibilityService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class NormativeCandidateSelectionServiceTest extends TestCase
{
    public function test_composition_only_readiness_blocker_keeps_session_in_review(): void
    {
        $service = (new \ReflectionClass(NormativeCandidateSelectionService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(NormativeCandidateSelectionService::class, 'draftRequiresReview');

        self::assertTrue($method->invoke($service, [
            'readiness_summary' => ['blocking_issues' => [['code' => 'required_scope_unresolved']]],
            'quality_summary' => [],
        ]));
    }

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

    public function test_confirming_current_priced_norm_preserves_resources_price_and_review_advice(): void
    {
        $workItem = [
            'key' => 'ventilation.ducts',
            'quantity' => 23.136,
            'pricing_status' => 'calculated',
            'pricing_blocker' => null,
            'total_cost' => 29910.50,
            'materials' => [[
                'code' => '19.1.01.03-0071',
                'total_price' => 16569.51,
                'project_resource_selection' => ['policy' => 'regional_semantic_hard_attributes_median:v4'],
            ]],
            'labor' => [['code' => '1-100-32', 'total_price' => 7779.24]],
            'machinery' => [],
            'other_resources' => [],
            'validation_flags' => ['project_resource_price_assumption'],
            'normative_match' => [
                'status' => 'matched',
                'selected_by_user' => false,
                'norm_id' => 501,
                'code' => '20-01-001-01',
                'project_resource_selections' => [[
                    'selected_resource_code' => '19.1.01.03-0071',
                ]],
            ],
        ];

        $confirmed = $this->service()->confirmCurrentPricedSelection($workItem, 501);

        self::assertNotNull($confirmed);
        self::assertTrue($confirmed['normative_match']['selected_by_user']);
        self::assertSame($workItem['materials'], $confirmed['materials']);
        self::assertSame($workItem['total_cost'], $confirmed['total_cost']);
        self::assertSame('calculated', $confirmed['pricing_status']);
        self::assertSame(['project_resource_price_assumption'], $confirmed['validation_flags']);
    }

    public function test_current_norm_with_incomplete_price_is_rebuilt_instead_of_confirmed(): void
    {
        self::assertNull($this->service()->confirmCurrentPricedSelection([
            'quantity' => 23.136,
            'pricing_status' => 'not_calculated',
            'pricing_blocker' => 'project_resource_selection_required',
            'total_cost' => 0,
            'materials' => [],
            'normative_match' => [
                'status' => 'matched',
                'norm_id' => 501,
            ],
        ], 501));
    }

    public function test_rejects_candidate_blocked_by_selection_hard_gate(): void
    {
        $service = $this->service($this->selectionHardGate());

        $this->expectException(ValidationException::class);

        $service->assertSafeMatch(['name' => 'Монтаж радиаторов отопления', 'unit' => 'шт'], [
            'scope_type' => 'engineering',
            'section_title' => 'Отопление',
            'object_type' => 'residential',
        ], [
            'selected' => [
                'name' => 'Монтаж воздухораспределителей офиса',
                'unit' => 'шт',
                'object_type' => 'office',
                'section' => ['code' => '20'],
            ],
        ]);
    }

    public function test_unsafe_catalog_candidate_is_rejected_before_apply_price_and_learning(): void
    {
        $matcher = $this->createMock(EstimateNormativeMatcher::class);
        $matcher->expects(self::once())
            ->method('matchSelectedNorm')
            ->willReturn([
                'selected' => [
                    'name' => 'Монтаж воздухораспределителей офиса',
                    'unit' => 'шт',
                    'object_type' => 'office',
                    'section' => ['code' => '20'],
                ],
            ]);
        $resourceAssembly = $this->createMock(ResourceAssemblyService::class);
        $resourceAssembly->expects(self::never())->method('applySelectedNormativeMatch');
        $pricing = $this->createMock(EstimatePricingService::class);
        $pricing->expects(self::never())->method('price');
        $service = new TestableNormativeCandidateSelectionService(
            $matcher,
            $resourceAssembly,
            $pricing,
            $this->createMock(EstimateValidationService::class),
            $this->createMock(EstimateGenerationPackagePersistenceService::class),
            (new \ReflectionClass(EstimateGenerationLearningRecorder::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(AdvanceEstimateGeneration::class))->newInstanceWithoutConstructor(),
            $this->selectionHardGate(),
        );
        $session = new EstimateGenerationSession;
        $session->forceFill([
            'input_payload' => [],
            'analysis_payload' => [],
            'draft_payload' => [
                'object_profile' => ['object_type' => 'residential'],
                'local_estimates' => [[
                    'scope_type' => 'engineering',
                    'title' => 'Инженерные системы',
                    'sections' => [[
                        'title' => 'Отопление',
                        'work_items' => [[
                            'key' => 'heating.radiators',
                            'name' => 'Монтаж радиаторов отопления',
                            'unit' => 'шт',
                            'normative_candidates' => [],
                        ]],
                    ]],
                ]],
            ],
        ]);

        $this->expectException(ValidationException::class);

        $service->selectWithoutTransaction($session, 'heating.radiators', 501, true);
    }

    private function service(?NormativeCandidateSelectionHardGate $selectionHardGate = null): TestableNormativeCandidateSelectionService
    {
        return new TestableNormativeCandidateSelectionService(
            $this->createMock(EstimateNormativeMatcher::class),
            $this->createMock(ResourceAssemblyService::class),
            $this->createMock(EstimatePricingService::class),
            $this->createMock(EstimateValidationService::class),
            $this->createMock(EstimateGenerationPackagePersistenceService::class),
            (new \ReflectionClass(EstimateGenerationLearningRecorder::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(AdvanceEstimateGeneration::class))->newInstanceWithoutConstructor(),
            $selectionHardGate ?? (new \ReflectionClass(NormativeCandidateSelectionHardGate::class))->newInstanceWithoutConstructor(),
        );
    }

    private function selectionHardGate(): NormativeCandidateSelectionHardGate
    {
        return new NormativeCandidateSelectionHardGate(
            new WorkIntentClassifier(new NormativeScopeRuleCatalog),
            new NormativeSearchProfileCatalog,
            new NormativeSemanticCompatibilityService,
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

    /**
     * @param  array<string, mixed>  $workItem
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $match
     */
    public function assertSafeMatch(array $workItem, array $context, array $match): void
    {
        $this->assertMatchPassesHardGate($workItem, $context, $match);
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @return array<string, mixed>|null
     */
    public function confirmCurrentPricedSelection(array $workItem, int $normId): ?array
    {
        return $this->confirmedCurrentPricedSelection($workItem, $normId);
    }

    /** @return array<string, mixed> */
    public function selectWithoutTransaction(
        EstimateGenerationSession $session,
        string $workItemKey,
        int $normId,
        bool $allowCatalogSelection,
    ): array {
        return $this->selectLocked($session, $workItemKey, $normId, $allowCatalogSelection);
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
