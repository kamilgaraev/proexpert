<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\RuleBasedDrawingAnalysisProvider;
use PHPUnit\Framework\TestCase;

final class RuleBasedDrawingAnalysisProviderTest extends TestCase
{
    public function test_extracts_drawing_elements_and_quantity_takeoffs_from_ocr_page(): void
    {
        $recognition = new OcrRecognitionResult(
            provider: 'test',
            model: 'page',
            pages: [
                new OcrPageResult(
                    pageNumber: 1,
                    text: implode("\n", [
                        'АР-2 План первого этажа',
                        'Масштаб 1:100',
                        'Помещение 101 Кабинет S=18,5 м2',
                        'Окно ОК-1 1200x1500 - 2 шт',
                        'Вентиляция В1 L=24 м DN100',
                    ]),
                    confidence: 0.86
                ),
            ]
        );

        $result = (new RuleBasedDrawingAnalysisProvider())->analyze(
            documentId: 10,
            filename: 'АР-2.pdf',
            recognition: $recognition
        );

        self::assertNotEmpty($result->elements);
        self::assertNotEmpty($result->takeoffs);
        self::assertContains('scale', array_column($result->elements, 'type'));
        self::assertContains('room', array_column($result->elements, 'type'));
        self::assertContains('opening', array_column($result->elements, 'type'));
        self::assertContains('engineering_route', array_column($result->elements, 'type'));

        $roomTakeoff = array_values(array_filter(
            $result->takeoffs,
            static fn (array $takeoff): bool => ($takeoff['scope_key'] ?? null) === 'room_area'
        ))[0] ?? null;

        self::assertIsArray($roomTakeoff);
        self::assertSame('м2', $roomTakeoff['unit']);
        self::assertSame(18.5, $roomTakeoff['quantity']);
        self::assertSame(1, $roomTakeoff['source_refs'][0]['page_number']);
    }
}
