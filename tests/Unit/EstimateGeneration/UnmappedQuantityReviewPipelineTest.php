<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\RuleBasedDrawingAnalysisProvider;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDecompositionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationReviewItemService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;
use PHPUnit\Framework\TestCase;

final class UnmappedQuantityReviewPipelineTest extends TestCase
{
    public function test_unmapped_work_volume_row_reaches_blocking_quantity_review_queue(): void
    {
        $recognition = new OcrRecognitionResult(
            provider: 'test',
            model: 'pdf',
            pages: [
                new OcrPageResult(
                    pageNumber: 1,
                    text: implode("\n", [
                        'Ведомость объемов работ',
                        'Наименование работ Ед. изм. Количество',
                        '1 Авторский надзор компл 1',
                        '2 Обратная засыпка пазух м3 42',
                    ]),
                    blocks: [[
                        'text' => '',
                        'bounding_box' => null,
                        'lines' => [[
                            'text' => '1 Авторский надзор компл 1',
                            'bounding_box' => ['x' => 24, 'y' => 120, 'width' => 340, 'height' => 24],
                            'words' => [],
                        ]],
                    ]],
                    confidence: 0.92
                ),
            ]
        );
        $drawing = (new RuleBasedDrawingAnalysisProvider())->analyze(
            documentId: 80,
            filename: 'Ведомость объемов работ.pdf',
            recognition: $recognition
        );
        $scopeInferences = (new EstimatorScopeInferenceService())->inferFromDocumentPayload([
            'id' => 80,
            'filename' => 'Ведомость объемов работ.pdf',
            'drawing_elements' => $drawing->elements,
            'quantity_takeoffs' => $drawing->takeoffs,
        ]);
        $analysis = (new ConstructionSemanticParser())->parse([
            'description' => '',
        ], [[
            'id' => 80,
            'filename' => 'Ведомость объемов работ.pdf',
            'status' => 'ready',
            'quality' => ['level' => 'good', 'score' => 0.92, 'flags' => []],
            'extracted_text' => $recognition->pages[0]->text,
            'facts_summary' => [
                'document_understanding' => [
                    'role_for_estimation' => 'quantity_source',
                    'document_type' => 'work_volume_statement',
                ],
                'zones' => [],
                'engineering_systems' => [],
                'conflicts' => [],
            ],
            'facts' => [],
            'drawing_elements' => $drawing->elements,
            'quantity_takeoffs' => $drawing->takeoffs,
            'scope_inferences' => $scopeInferences,
        ]]);
        $packagePlanner = new PackagePlannerService();
        $profile = $packagePlanner->profileFromAnalysis($analysis);
        $plan = $packagePlanner->plan($profile);
        $decomposition = (new EstimateDecompositionService())->decomposePackagePlan($analysis, $plan);
        $workItemPlanner = new NormativeWorkItemPlannerService(
            new ProjectDocumentNormativeReferenceExtractor(),
            new EstimatorScopeInferenceService()
        );
        $draftLocalEstimates = [];
        $allItems = [];

        foreach ($decomposition as $localEstimate) {
            $section = $localEstimate['sections'][0];
            $items = $workItemPlanner->build($localEstimate, $section, $analysis);
            $localEstimate['sections'][0]['work_items'] = $items;
            $draftLocalEstimates[] = $localEstimate;
            array_push($allItems, ...$items);
        }

        $reviewItems = array_values(array_filter(
            $allItems,
            static fn (array $item): bool => ($item['item_type'] ?? null) === 'quantity_review'
                && ($item['name'] ?? null) === 'Авторский надзор'
        ));
        $unmappedPackageItems = array_values(array_filter(
            $allItems,
            static fn (array $item): bool => ($item['metadata']['package_key'] ?? null) === 'unmapped_quantity_rows'
        ));
        $reviewQueue = (new EstimateGenerationReviewItemService(
            new EstimateGenerationPackagePresenter()
        ))->forSession(new EstimateGenerationSession([
            'draft_payload' => ['local_estimates' => $draftLocalEstimates],
        ]));

        self::assertContains('unmapped_quantity_rows', array_column($plan->packages, 'key'));
        self::assertCount(1, $reviewItems);
        self::assertSame('компл', $reviewItems[0]['unit']);
        self::assertSame(1.0, $reviewItems[0]['quantity']);
        self::assertStringStartsWith('unmapped.', $reviewItems[0]['quantity_formula']);
        self::assertSame('quantity_review_required', $reviewItems[0]['pricing_blocker']);
        self::assertSame('work_volume_statement', $reviewItems[0]['metadata']['quantity_source']);
        self::assertNotEmpty($reviewItems[0]['source_refs']);
        self::assertSame(['Авторский надзор'], array_column($unmappedPackageItems, 'name'));
        self::assertSame(1, $reviewQueue['summary']['blocking']);
        self::assertSame('confirm_quantity', $reviewQueue['items'][0]['required_action']);
        self::assertSame('Авторский надзор', $reviewQueue['items'][0]['work_item']['name']);
    }
}
