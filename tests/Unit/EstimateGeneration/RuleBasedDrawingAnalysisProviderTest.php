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

    public function test_classifies_floor_plan_and_builds_aggregate_takeoffs_from_room_areas(): void
    {
        $recognition = new OcrRecognitionResult(
            provider: 'test',
            model: 'page',
            pages: [
                new OcrPageResult(
                    pageNumber: 1,
                    text: implode("\n", [
                        'Планировка квартиры',
                        'Гостиная 46,52 м²',
                        'Кухня 9.99 м2',
                        'Спальня 17,65 м²',
                        'Санузел 5,14 м²',
                        'Коридор 7,84 м²',
                        '8755 x 6190',
                        'Дверь 900x2100 - 8 шт',
                    ]),
                    confidence: 0.91
                ),
            ]
        );

        $result = (new RuleBasedDrawingAnalysisProvider())->analyze(
            documentId: 10,
            filename: 'flat-plan.png',
            recognition: $recognition
        );
        $takeoffsByKey = [];

        foreach ($result->takeoffs as $takeoff) {
            $payload = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];
            $takeoffsByKey[(string) ($payload['quantity_key'] ?? $takeoff['scope_key'] ?? '')] = $takeoff;
        }

        self::assertSame('floor_plan', $result->summary['document_profile']['document_role'] ?? null);
        self::assertSame('floor_plan', $result->summary['page_profiles'][0]['page_role'] ?? null);
        self::assertGreaterThanOrEqual(5, $result->summary['room_count'] ?? 0);
        self::assertSame(87.14, $result->summary['room_area_total_m2'] ?? null);
        self::assertArrayHasKey('finish.floor', $takeoffsByKey);
        self::assertArrayHasKey('rough.floor', $takeoffsByKey);
        self::assertArrayHasKey('office.ceiling', $takeoffsByKey);
        self::assertArrayHasKey('rough.walls', $takeoffsByKey);
        self::assertSame(87.14, $takeoffsByKey['finish.floor']['quantity']);
        self::assertSame(87.14, $takeoffsByKey['rough.floor']['quantity']);
        self::assertSame(87.14, $takeoffsByKey['office.ceiling']['quantity']);
        self::assertGreaterThan(87.14, $takeoffsByKey['rough.walls']['quantity']);
        self::assertTrue($takeoffsByKey['rough.walls']['normalized_payload']['review_required'] ?? false);
        self::assertSame(8.0, $takeoffsByKey['openings.doors']['quantity']);
    }
}
