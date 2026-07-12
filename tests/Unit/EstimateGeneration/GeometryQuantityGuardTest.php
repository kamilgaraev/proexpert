<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\RuleBasedDrawingAnalysisProvider;
use PHPUnit\Framework\TestCase;

final class GeometryQuantityGuardTest extends TestCase
{
    public function test_specification_dimensions_do_not_create_geometry_takeoffs(): void
    {
        $recognition = new OcrRecognitionResult(
            provider: 'test',
            model: 'unit',
            pages: [
                new OcrPageResult(
                    pageNumber: 5,
                    text: "Плита сбора мусора\nТруба стальная 108x4 шт 12\nЛист 2000x1000 шт 4",
                    rawPayload: [
                        'geometry' => [
                            'page_role' => 'geometry_only',
                            'signals' => ['vector_geometry'],
                            'visual_metrics' => ['line_count' => 80, 'table_candidate_count' => 1],
                        ],
                    ],
                ),
            ]
        );

        $result = (new RuleBasedDrawingAnalysisProvider)->analyze(10, '11174-PZU_AS_gaz_izm_4.pdf', $recognition);

        self::assertEmpty(array_filter(
            $result->takeoffs,
            static fn (array $takeoff): bool => in_array($takeoff['scope_key'] ?? null, ['floor_finish_area', 'rough_floor_area', 'wall_finish_area'], true)
        ));
        self::assertTrue($result->summary['document_profile']['requires_manual_review']);
        self::assertSame(['normalized_building_model_missing'], $result->summary['review_reasons']);
    }
}
