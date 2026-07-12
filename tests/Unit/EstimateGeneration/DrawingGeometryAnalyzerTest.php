<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\DrawingGeometryAnalyzer;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\RuleBasedDrawingAnalysisProvider;
use PHPUnit\Framework\TestCase;

final class DrawingGeometryAnalyzerTest extends TestCase
{
    public function test_pixel_geometry_without_normalized_model_produces_no_invented_elements(): void
    {
        $recognition = new OcrRecognitionResult(
            provider: 'pdf_geometry',
            model: 'pymupdf_geometry_v1',
            pages: [
                new OcrPageResult(
                    pageNumber: 5,
                    text: '',
                    rawPayload: [
                        'geometry' => [
                            'page_role' => 'geometry_only',
                            'signals' => ['vector_geometry', 'table_candidate', 'text_empty'],
                            'visual_metrics' => [
                                'line_count' => 120,
                                'curve_count' => 3,
                                'rect_count' => 14,
                                'contour_candidate_count' => 2,
                                'table_candidate_count' => 1,
                            ],
                            'vector_elements' => [
                                [
                                    'kind' => 'line',
                                    'bbox' => ['x' => 10, 'y' => 10, 'width' => 100, 'height' => 0],
                                    'geometry' => ['points' => [[10, 10], [110, 10]]],
                                ],
                                [
                                    'kind' => 'rect',
                                    'bbox' => ['x' => 400, 'y' => 440, 'width' => 220, 'height' => 80],
                                    'geometry' => ['rect' => [400, 440, 620, 520]],
                                ],
                            ],
                        ],
                    ],
                ),
            ]
        );

        $result = (new RuleBasedDrawingAnalysisProvider)->analyze(10, '11174-PZU_AS_gaz_izm_4.pdf', $recognition);

        self::assertSame('geometry_only', $result->summary['page_profiles'][0]['page_role']);
        self::assertSame(0, $result->summary['geometry_metrics']['line_count']);
        self::assertSame(['normalized_building_model_missing'], $result->summary['evidence_graph']['review_reasons']);
        self::assertTrue($result->summary['document_profile']['requires_manual_review']);
        self::assertNotContains('geometry_metric', array_column($result->elements, 'type'));
        self::assertNotContains('table', array_column($result->elements, 'type'));
        self::assertEmpty($result->takeoffs);
    }

    public function test_analyzer_is_thin_adapter_for_normalized_building_model(): void
    {
        $recognition = new OcrRecognitionResult(provider: 'test', model: 'unit', pages: [
            new OcrPageResult(pageNumber: 1, text: 'ignored filename and text 999x999', rawPayload: [
                'normalized_building_model' => [
                    'model_version' => 'building-model.v1',
                    'scale' => ['status' => 'confirmed', 'unit' => 'm'],
                    'rooms' => [['id' => 'r', 'area' => '7.25', 'evidence_ids' => ['e']]],
                ],
            ]),
        ]);

        $result = (new DrawingGeometryAnalyzer)->analyze(1, '999x999.pdf', $recognition);

        self::assertSame('7.250000', $result['quantities'][0]['amount']);
        self::assertSame([], $result['elements']);
    }
}
