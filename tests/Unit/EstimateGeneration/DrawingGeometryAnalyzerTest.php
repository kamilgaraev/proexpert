<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\RuleBasedDrawingAnalysisProvider;
use PHPUnit\Framework\TestCase;

final class DrawingGeometryAnalyzerTest extends TestCase
{
    public function test_geometry_only_page_produces_ir_elements_without_takeoffs(): void
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

        $result = (new RuleBasedDrawingAnalysisProvider())->analyze(10, '11174-PZU_AS_gaz_izm_4.pdf', $recognition);

        self::assertSame('geometry_only', $result->summary['page_profiles'][0]['page_role']);
        self::assertGreaterThan(0, $result->summary['geometry_metrics']['line_count']);
        self::assertContains('geometry_requires_review', $result->summary['evidence_graph']['review_reasons']);
        self::assertContains('geometry_metric', array_column($result->elements, 'type'));
        self::assertContains('table', array_column($result->elements, 'type'));
        self::assertEmpty($result->takeoffs);
    }
}
