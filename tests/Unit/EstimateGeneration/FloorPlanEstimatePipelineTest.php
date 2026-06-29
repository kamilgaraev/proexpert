<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\RuleBasedDrawingAnalysisProvider;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDecompositionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;
use PHPUnit\Framework\TestCase;

final class FloorPlanEstimatePipelineTest extends TestCase
{
    public function test_floor_plan_ocr_generates_only_evidence_backed_fitout_items(): void
    {
        $recognition = new OcrRecognitionResult(
            provider: 'test',
            model: 'page',
            pages: [
                new OcrPageResult(
                    pageNumber: 1,
                    text: implode("\n", [
                        'Планировка квартиры',
                        'Высота потолка 3,0 м',
                        'Гостиная 46,52 м²',
                        'Кухня 9.99 м2',
                        'Санузел 5,14 м²',
                        'Дверь 900x2100 - 5 шт',
                    ]),
                    confidence: 0.91
                ),
            ]
        );
        $drawing = (new RuleBasedDrawingAnalysisProvider())->analyze(80, 'flat-plan.png', $recognition);
        $analysis = (new ConstructionSemanticParser())->parse([
            'description' => '',
        ], [[
            'id' => 80,
            'filename' => 'flat-plan.png',
            'status' => 'ready',
            'quality' => ['level' => 'good', 'score' => 0.91, 'flags' => []],
            'extracted_text' => $recognition->pages[0]->text,
            'facts_summary' => [
                'drawing_understanding' => $drawing->summary,
                'zones' => [],
                'engineering_systems' => [],
                'conflicts' => [],
            ],
            'facts' => [],
            'drawing_elements' => $drawing->elements,
            'quantity_takeoffs' => $drawing->takeoffs,
        ]]);
        $planner = new PackagePlannerService();
        $profile = $planner->profileFromAnalysis($analysis);
        $plan = $planner->plan($profile);
        $decomposition = (new EstimateDecompositionService())->decomposePackagePlan($analysis, $plan);
        $workItemPlanner = new NormativeWorkItemPlannerService(
            new ProjectDocumentNormativeReferenceExtractor(),
            new EstimatorScopeInferenceService()
        );
        $items = [];

        foreach ($decomposition as $localEstimate) {
            array_push($items, ...$workItemPlanner->build($localEstimate, $localEstimate['sections'][0], $analysis));
        }

        $packageKeys = array_column($decomposition, 'key');
        $pricedFormulas = $this->quantityFormulasByItemType($items, 'priced_work');
        $reviewFormulas = $this->quantityFormulasByItemType($items, 'quantity_review');

        self::assertSame('floor_plan_geometry', $profile->objectType);
        self::assertContains('rough_finishing', $packageKeys);
        self::assertContains('finish_finishing', $packageKeys);
        self::assertContains('openings', $packageKeys);
        self::assertContains('plumbing', $packageKeys);
        self::assertNotContains('foundation', $packageKeys);
        self::assertNotContains('roof', $packageKeys);
        self::assertNotContains('ventilation', $packageKeys);
        self::assertNotContains('fire_safety', $packageKeys);
        self::assertContains('rough.floor', $pricedFormulas);
        self::assertContains('finish.floor', $pricedFormulas);
        self::assertContains('office.ceiling', $pricedFormulas);
        self::assertContains('openings.doors', $pricedFormulas);
        self::assertContains('rough.walls', $reviewFormulas);
        self::assertContains('finish.paint', $reviewFormulas);
        self::assertContains('finish.baseboard', $reviewFormulas);
        self::assertContains('sanitary.tile', $reviewFormulas);
        self::assertNotContains('ventilation.air_exchange', array_column($items, 'quantity_formula'));
        self::assertNotContains('warehouse.fire', array_column($items, 'quantity_formula'));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, string>
     */
    private function quantityFormulasByItemType(array $items, string $itemType): array
    {
        return array_values(array_map(
            static fn (array $item): string => (string) ($item['quantity_formula'] ?? ''),
            array_filter($items, static fn (array $item): bool => ($item['item_type'] ?? null) === $itemType)
        ));
    }
}
