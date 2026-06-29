<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Contracts\DrawingAnalysisProviderInterface;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Documents\DrawingAnalysisResultData;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\DrawingUnderstandingService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class DrawingUnderstandingServiceTest extends TestCase
{
    public function test_matches_takeoff_source_refs_to_persisted_drawing_element_ids(): void
    {
        $service = new DrawingUnderstandingService(new class implements DrawingAnalysisProviderInterface {
            public function analyze(int $documentId, string $filename, OcrRecognitionResult $recognition): DrawingAnalysisResultData
            {
                return new DrawingAnalysisResultData(elements: [], takeoffs: []);
            }
        });
        $method = new ReflectionMethod(DrawingUnderstandingService::class, 'matchingSourceElementIds');
        $method->setAccessible(true);

        $ids = $method->invoke($service, [
            'source_refs' => [[
                'page_number' => 1,
                'excerpt' => 'Гостиная 46,52 м²',
                'line_hash' => 'abc123',
                'bbox' => ['x' => 120.0, 'y' => 260.0, 'width' => 160.0, 'height' => 40.0],
            ]],
        ], [
            [
                'id' => 51,
                'source_ref' => [
                    'page_number' => 1,
                    'excerpt' => 'Гостиная 46,52 м²',
                    'line_hash' => 'abc123',
                    'bbox' => ['x' => 120.0, 'y' => 260.0, 'width' => 160.0, 'height' => 40.0],
                ],
            ],
            [
                'id' => 52,
                'source_ref' => [
                    'page_number' => 1,
                    'excerpt' => 'Кухня 9,99 м2',
                    'line_hash' => 'def456',
                    'bbox' => ['x' => 320.0, 'y' => 260.0, 'width' => 110.0, 'height' => 32.0],
                ],
            ],
        ]);

        self::assertSame([51], $ids);
    }
}
