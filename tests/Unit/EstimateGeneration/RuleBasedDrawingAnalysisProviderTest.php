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

    public function test_attaches_ocr_line_bbox_to_elements_takeoffs_and_aggregate_sources(): void
    {
        $roomBbox = [
            'vertices' => [
                ['x' => 120, 'y' => 260],
                ['x' => 280, 'y' => 260],
                ['x' => 280, 'y' => 300],
                ['x' => 120, 'y' => 300],
            ],
        ];
        $normalizedRoomBbox = ['x' => 120.0, 'y' => 260.0, 'width' => 160.0, 'height' => 40.0];

        $recognition = new OcrRecognitionResult(
            provider: 'test',
            model: 'page',
            pages: [
                new OcrPageResult(
                    pageNumber: 1,
                    text: implode("\n", [
                        'Планировка квартиры',
                        'Гостиная 46,52 м²',
                        'Кухня 9,99 м2',
                    ]),
                    blocks: [[
                        'text' => '',
                        'bounding_box' => null,
                        'lines' => [
                            [
                                'text' => 'Планировка квартиры',
                                'bounding_box' => ['x' => 10, 'y' => 20, 'width' => 180, 'height' => 24],
                                'words' => [],
                            ],
                            [
                                'text' => 'Гостиная 46,52 м²',
                                'bounding_box' => $roomBbox,
                                'words' => [],
                            ],
                            [
                                'text' => 'Кухня 9,99 м2',
                                'bounding_box' => ['x' => 320, 'y' => 260, 'width' => 110, 'height' => 32],
                                'words' => [],
                            ],
                        ],
                    ]],
                    width: 1200,
                    height: 800,
                    confidence: 0.93,
                ),
            ]
        );

        $result = (new RuleBasedDrawingAnalysisProvider())->analyze(
            documentId: 10,
            filename: 'flat-plan.png',
            recognition: $recognition
        );

        $roomElement = array_values(array_filter(
            $result->elements,
            static fn (array $element): bool => ($element['type'] ?? null) === 'room'
                && ($element['label'] ?? null) === 'Гостиная'
        ))[0] ?? null;
        $roomTakeoff = array_values(array_filter(
            $result->takeoffs,
            static fn (array $takeoff): bool => ($takeoff['scope_key'] ?? null) === 'room_area'
                && ($takeoff['name'] ?? null) === 'Гостиная'
        ))[0] ?? null;
        $floorAggregate = array_values(array_filter(
            $result->takeoffs,
            static function (array $takeoff): bool {
                $payload = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];

                return ($payload['quantity_key'] ?? null) === 'finish.floor';
            }
        ))[0] ?? null;

        self::assertIsArray($roomElement);
        self::assertSame($normalizedRoomBbox, $roomElement['bbox']);
        self::assertSame($normalizedRoomBbox, $roomElement['source_ref']['bbox']);
        self::assertSame('ocr_line', $roomElement['source_ref']['evidence_kind']);
        self::assertSame(0, $roomElement['source_ref']['block_index']);
        self::assertSame(1, $roomElement['source_ref']['line_index']);
        self::assertSame($normalizedRoomBbox, $roomElement['normalized_payload']['ocr_line_bbox']);

        self::assertIsArray($roomTakeoff);
        self::assertSame($normalizedRoomBbox, $roomTakeoff['source_refs'][0]['bbox']);
        self::assertSame('ocr_line', $roomTakeoff['source_refs'][0]['evidence_kind']);
        self::assertSame($normalizedRoomBbox, $roomTakeoff['normalized_payload']['ocr_line_bbox']);

        self::assertIsArray($floorAggregate);
        self::assertNotEmpty(array_filter(
            $floorAggregate['source_refs'],
            static fn (array $sourceRef): bool => ($sourceRef['bbox'] ?? null) === $normalizedRoomBbox
        ));
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
                        'Высота потолка 3,0 м',
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
        self::assertContains('height', array_column($result->elements, 'type'));
        self::assertGreaterThanOrEqual(5, $result->summary['room_count'] ?? 0);
        self::assertSame(87.14, $result->summary['room_area_total_m2'] ?? null);
        self::assertSame(3.0, $result->summary['detected_height_m'] ?? null);
        self::assertSame('review_required', $result->summary['evidence_graph']['quality_level'] ?? null);
        self::assertGreaterThanOrEqual(1, $result->summary['evidence_graph']['review_required_count'] ?? 0);
        self::assertGreaterThanOrEqual(1, $result->summary['evidence_graph']['nodes_count'] ?? 0);
        self::assertGreaterThanOrEqual(1, $result->summary['evidence_graph']['nodes'][0]['source_refs_count'] ?? 0);
        self::assertArrayHasKey('finish.floor', $takeoffsByKey);
        self::assertArrayHasKey('rough.floor', $takeoffsByKey);
        self::assertArrayHasKey('office.ceiling', $takeoffsByKey);
        self::assertArrayHasKey('rough.walls', $takeoffsByKey);
        self::assertArrayHasKey('finish.paint', $takeoffsByKey);
        self::assertArrayHasKey('sanitary.tile', $takeoffsByKey);
        self::assertArrayHasKey('finish.baseboard', $takeoffsByKey);
        self::assertSame(87.14, $takeoffsByKey['finish.floor']['quantity']);
        self::assertSame(87.14, $takeoffsByKey['rough.floor']['quantity']);
        self::assertSame(87.14, $takeoffsByKey['office.ceiling']['quantity']);
        self::assertFalse($takeoffsByKey['finish.floor']['normalized_payload']['review_required'] ?? true);
        self::assertFalse($takeoffsByKey['rough.floor']['normalized_payload']['review_required'] ?? true);
        self::assertTrue($takeoffsByKey['office.ceiling']['normalized_payload']['review_required'] ?? false);
        self::assertSame(215.88, $takeoffsByKey['rough.walls']['quantity']);
        self::assertSame($takeoffsByKey['rough.walls']['quantity'], $takeoffsByKey['finish.paint']['quantity']);
        self::assertGreaterThan(5.14, $takeoffsByKey['sanitary.tile']['quantity']);
        self::assertSame(69.8, $takeoffsByKey['finish.baseboard']['quantity']);
        self::assertSame('м', $takeoffsByKey['finish.baseboard']['unit']);
        self::assertSame(3.0, $takeoffsByKey['rough.walls']['normalized_payload']['height_m'] ?? null);
        self::assertSame(231.0, $takeoffsByKey['rough.walls']['normalized_payload']['gross_wall_area_m2'] ?? null);
        self::assertSame(15.12, $takeoffsByKey['rough.walls']['normalized_payload']['opening_area_m2'] ?? null);
        self::assertTrue($takeoffsByKey['rough.walls']['normalized_payload']['openings_subtracted'] ?? false);
        self::assertSame(77.0, $takeoffsByKey['finish.baseboard']['normalized_payload']['gross_baseboard_length_m'] ?? null);
        self::assertSame(7.2, $takeoffsByKey['finish.baseboard']['normalized_payload']['door_width_m'] ?? null);
        self::assertTrue($takeoffsByKey['finish.baseboard']['normalized_payload']['openings_subtracted'] ?? false);
        self::assertTrue($takeoffsByKey['rough.walls']['normalized_payload']['review_required'] ?? false);
        self::assertTrue($takeoffsByKey['finish.paint']['normalized_payload']['review_required'] ?? false);
        self::assertTrue($takeoffsByKey['sanitary.tile']['normalized_payload']['review_required'] ?? false);
        self::assertTrue($takeoffsByKey['finish.baseboard']['normalized_payload']['review_required'] ?? false);
        self::assertSame(8.0, $takeoffsByKey['openings.doors']['quantity']);
    }

    public function test_preserves_decimal_room_areas_without_room_labels_from_plan_ocr(): void
    {
        $recognition = new OcrRecognitionResult(
            provider: 'test',
            model: 'page',
            pages: [
                new OcrPageResult(
                    pageNumber: 1,
                    text: implode("\n", [
                        '5.14 м2',
                        '5.49 м2',
                        '7.84 м2',
                        '11.90 м2',
                        '5.00 м2',
                        '10.54 м2',
                        '2.55 м2',
                        '10.24 м2',
                        '4.34 м2',
                        '9.99 м2',
                        '17.65 м2',
                        '46.52 м2',
                        '9.87 м2',
                        '15.11 м2',
                        '7.21 м2',
                    ]),
                    confidence: 0.7
                ),
            ]
        );

        $result = (new RuleBasedDrawingAnalysisProvider())->analyze(
            documentId: 13,
            filename: 'floor-plan.jpg',
            recognition: $recognition
        );
        $roomTakeoffs = array_values(array_filter(
            $result->takeoffs,
            static fn (array $takeoff): bool => ($takeoff['scope_key'] ?? null) === 'room_area'
        ));
        $takeoffsByKey = [];

        foreach ($result->takeoffs as $takeoff) {
            $payload = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];
            $takeoffsByKey[(string) ($payload['quantity_key'] ?? $takeoff['scope_key'] ?? '')] = $takeoff;
        }

        self::assertCount(15, $roomTakeoffs);
        self::assertSame(169.39, $result->summary['room_area_total_m2'] ?? null);
        self::assertSame(5.14, $roomTakeoffs[0]['quantity']);
        self::assertSame(5.49, $roomTakeoffs[1]['quantity']);
        self::assertSame(7.84, $roomTakeoffs[2]['quantity']);
        self::assertSame(169.39, $takeoffsByKey['finish.floor']['quantity']);
        self::assertSame(169.39, $takeoffsByKey['rough.floor']['quantity']);
        self::assertNotSame(464.18, $takeoffsByKey['finish.floor']['quantity']);
        self::assertTrue($takeoffsByKey['finish.floor']['normalized_payload']['review_required'] ?? false);
        self::assertTrue($takeoffsByKey['rough.floor']['normalized_payload']['review_required'] ?? false);
        self::assertSame('unlabeled_room_areas', $takeoffsByKey['finish.floor']['normalized_payload']['review_reason'] ?? null);
        self::assertSame(0, $takeoffsByKey['finish.floor']['normalized_payload']['labeled_room_count'] ?? null);
        self::assertSame(15, $takeoffsByKey['finish.floor']['normalized_payload']['unlabeled_room_count'] ?? null);
    }

    public function test_uses_explicit_overall_dimension_pair_as_review_required_footprint_when_rooms_are_missing(): void
    {
        $recognition = new OcrRecognitionResult(
            provider: 'test',
            model: 'page',
            pages: [
                new OcrPageResult(
                    pageNumber: 1,
                    text: implode("\n", [
                        'Планировка дома',
                        'Высота потолка 3,0 м',
                        '14845 x 8755',
                    ]),
                    blocks: [[
                        'text' => '',
                        'lines' => [
                            [
                                'text' => 'Планировка дома',
                                'bounding_box' => ['x' => 20, 'y' => 20, 'width' => 180, 'height' => 24],
                                'words' => [],
                            ],
                            [
                                'text' => 'Высота потолка 3,0 м',
                                'bounding_box' => ['x' => 40, 'y' => 80, 'width' => 140, 'height' => 18],
                                'words' => [],
                            ],
                            [
                                'text' => '14845 x 8755',
                                'bounding_box' => ['x' => 180, 'y' => 520, 'width' => 220, 'height' => 26],
                                'words' => [],
                            ],
                        ],
                    ]],
                    width: 1200,
                    height: 800,
                    confidence: 0.88
                ),
            ]
        );

        $result = (new RuleBasedDrawingAnalysisProvider())->analyze(
            documentId: 13,
            filename: 'house-floor-plan.jpg',
            recognition: $recognition
        );
        $takeoffsByKey = [];

        foreach ($result->takeoffs as $takeoff) {
            $payload = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];
            $takeoffsByKey[(string) ($payload['quantity_key'] ?? $takeoff['scope_key'] ?? '')] = $takeoff;
        }

        self::assertSame('floor_plan', $result->summary['document_profile']['document_role'] ?? null);
        self::assertArrayHasKey('finish.floor', $takeoffsByKey);
        self::assertArrayHasKey('rough.floor', $takeoffsByKey);
        self::assertArrayHasKey('rough.walls', $takeoffsByKey);
        self::assertArrayHasKey('finish.baseboard', $takeoffsByKey);
        self::assertSame(129.97, $takeoffsByKey['finish.floor']['quantity']);
        self::assertSame(129.97, $takeoffsByKey['rough.floor']['quantity']);
        self::assertSame(141.6, $takeoffsByKey['rough.walls']['quantity']);
        self::assertSame(47.2, $takeoffsByKey['finish.baseboard']['quantity']);
        self::assertTrue($takeoffsByKey['finish.floor']['normalized_payload']['review_required'] ?? false);
        self::assertSame('footprint_dimension_pair', $takeoffsByKey['finish.floor']['normalized_payload']['calculation_basis'] ?? null);
        self::assertSame(14.845, $takeoffsByKey['finish.floor']['normalized_payload']['length_m'] ?? null);
        self::assertSame(8.755, $takeoffsByKey['finish.floor']['normalized_payload']['width_m'] ?? null);
        self::assertSame(3.0, $takeoffsByKey['rough.walls']['normalized_payload']['height_m'] ?? null);
    }

    public function test_marks_room_area_sum_for_review_when_overall_dimensions_show_incomplete_coverage(): void
    {
        $recognition = new OcrRecognitionResult(
            provider: 'test',
            model: 'page',
            pages: [
                new OcrPageResult(
                    pageNumber: 1,
                    text: implode("\n", [
                        'Floor plan house',
                        'h=3.0 m',
                        'Living 46.52 m2',
                        'Kitchen 9.99 m2',
                        'Bathroom 5.14 m2',
                        '14845 x 8755',
                    ]),
                    blocks: [[
                        'text' => '',
                        'lines' => [
                            [
                                'text' => 'Floor plan house',
                                'bounding_box' => ['x' => 20, 'y' => 20, 'width' => 180, 'height' => 24],
                                'words' => [],
                            ],
                            [
                                'text' => 'h=3.0 m',
                                'bounding_box' => ['x' => 40, 'y' => 80, 'width' => 140, 'height' => 18],
                                'words' => [],
                            ],
                            [
                                'text' => 'Living 46.52 m2',
                                'bounding_box' => ['x' => 240, 'y' => 240, 'width' => 145, 'height' => 28],
                                'words' => [],
                            ],
                            [
                                'text' => 'Kitchen 9.99 m2',
                                'bounding_box' => ['x' => 500, 'y' => 240, 'width' => 120, 'height' => 24],
                                'words' => [],
                            ],
                            [
                                'text' => 'Bathroom 5.14 m2',
                                'bounding_box' => ['x' => 250, 'y' => 140, 'width' => 120, 'height' => 24],
                                'words' => [],
                            ],
                            [
                                'text' => '14845 x 8755',
                                'bounding_box' => ['x' => 180, 'y' => 520, 'width' => 220, 'height' => 26],
                                'words' => [],
                            ],
                        ],
                    ]],
                    width: 1200,
                    height: 800,
                    confidence: 0.9
                ),
            ]
        );

        $result = (new RuleBasedDrawingAnalysisProvider())->analyze(
            documentId: 13,
            filename: 'house-floor-plan.jpg',
            recognition: $recognition
        );
        $takeoffsByKey = [];

        foreach ($result->takeoffs as $takeoff) {
            $payload = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];
            $takeoffsByKey[(string) ($payload['quantity_key'] ?? $takeoff['scope_key'] ?? '')] = $takeoff;
        }

        self::assertSame(61.65, $takeoffsByKey['finish.floor']['quantity']);
        self::assertSame(61.65, $takeoffsByKey['rough.floor']['quantity']);
        self::assertTrue($takeoffsByKey['finish.floor']['normalized_payload']['review_required'] ?? false);
        self::assertSame(
            'room_area_footprint_mismatch',
            $takeoffsByKey['finish.floor']['normalized_payload']['review_reason'] ?? null
        );
        self::assertContains(
            'room_area_footprint_mismatch',
            $takeoffsByKey['finish.floor']['normalized_payload']['review_reasons'] ?? []
        );
        self::assertSame(129.97, $takeoffsByKey['finish.floor']['normalized_payload']['footprint_area_m2'] ?? null);
        self::assertSame(14.845, $takeoffsByKey['finish.floor']['normalized_payload']['footprint_length_m'] ?? null);
        self::assertSame(8.755, $takeoffsByKey['finish.floor']['normalized_payload']['footprint_width_m'] ?? null);
        self::assertSame(0.4743, $takeoffsByKey['finish.floor']['normalized_payload']['room_to_footprint_area_ratio'] ?? null);
        self::assertSame(68.32, $takeoffsByKey['finish.floor']['normalized_payload']['missing_room_area_against_footprint_m2'] ?? null);
        self::assertNotEmpty(array_filter(
            $takeoffsByKey['finish.floor']['source_refs'],
            static fn (array $sourceRef): bool => ($sourceRef['excerpt'] ?? null) === '14845 x 8755'
        ));
    }

    public function test_uses_nearby_dimension_pair_to_estimate_room_perimeter(): void
    {
        $recognition = new OcrRecognitionResult(
            provider: 'test',
            model: 'page',
            pages: [
                new OcrPageResult(
                    pageNumber: 1,
                    text: implode("\n", [
                        'Планировка квартиры',
                        'h=3,0 m',
                        'Гостиная 46,52 м2',
                        '8755 x 6190',
                    ]),
                    blocks: [[
                        'text' => '',
                        'lines' => [
                            [
                                'text' => 'Планировка квартиры',
                                'bounding_box' => ['x' => 20, 'y' => 20, 'width' => 180, 'height' => 24],
                                'words' => [],
                            ],
                            [
                                'text' => 'h=3,0 m',
                                'bounding_box' => ['x' => 60, 'y' => 80, 'width' => 90, 'height' => 18],
                                'words' => [],
                            ],
                            [
                                'text' => 'Гостиная 46,52 м2',
                                'bounding_box' => ['x' => 240, 'y' => 240, 'width' => 145, 'height' => 28],
                                'words' => [],
                            ],
                            [
                                'text' => '8755 x 6190',
                                'bounding_box' => ['x' => 250, 'y' => 282, 'width' => 135, 'height' => 22],
                                'words' => [],
                            ],
                        ],
                    ]],
                    width: 1200,
                    height: 800,
                    confidence: 0.92
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

        self::assertSame(89.67, $takeoffsByKey['rough.walls']['quantity']);
        self::assertSame(29.89, $takeoffsByKey['finish.baseboard']['quantity']);
        self::assertSame('room_dimension_geometry', $takeoffsByKey['rough.walls']['normalized_payload']['calculation_basis']);
        self::assertSame(1, $takeoffsByKey['rough.walls']['normalized_payload']['room_dimension_count']);
        self::assertSame(8.755, $takeoffsByKey['rough.walls']['normalized_payload']['room_dimensions'][0]['length_m']);
        self::assertSame(6.19, $takeoffsByKey['rough.walls']['normalized_payload']['room_dimensions'][0]['width_m']);
    }

    public function test_uses_nearby_orthogonal_dimension_lines_to_estimate_room_perimeter(): void
    {
        $recognition = new OcrRecognitionResult(
            provider: 'test',
            model: 'page',
            pages: [
                new OcrPageResult(
                    pageNumber: 1,
                    text: implode("\n", [
                        'Планировка квартиры',
                        'h=3,0 m',
                        '3255',
                        'Санузел 5,14 м2',
                        '1580',
                    ]),
                    blocks: [[
                        'text' => '',
                        'lines' => [
                            [
                                'text' => 'Планировка квартиры',
                                'bounding_box' => ['x' => 20, 'y' => 20, 'width' => 180, 'height' => 24],
                                'words' => [],
                            ],
                            [
                                'text' => 'h=3,0 m',
                                'bounding_box' => ['x' => 40, 'y' => 80, 'width' => 80, 'height' => 18],
                                'words' => [],
                            ],
                            [
                                'text' => '3255',
                                'bounding_box' => ['x' => 238, 'y' => 192, 'width' => 95, 'height' => 16],
                                'words' => [],
                            ],
                            [
                                'text' => 'Санузел 5,14 м2',
                                'bounding_box' => ['x' => 255, 'y' => 245, 'width' => 118, 'height' => 26],
                                'words' => [],
                            ],
                            [
                                'text' => '1580',
                                'bounding_box' => ['x' => 205, 'y' => 220, 'width' => 16, 'height' => 78],
                                'words' => [],
                            ],
                        ],
                    ]],
                    width: 1200,
                    height: 800,
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

        self::assertSame(29.01, $takeoffsByKey['rough.walls']['quantity']);
        self::assertSame(9.67, $takeoffsByKey['finish.baseboard']['quantity']);
        self::assertSame('room_dimension_geometry', $takeoffsByKey['rough.walls']['normalized_payload']['calculation_basis']);
        self::assertSame('orthogonal_dimension_geometry', $takeoffsByKey['rough.walls']['normalized_payload']['room_dimensions'][0]['basis']);
        self::assertSame(3.255, $takeoffsByKey['rough.walls']['normalized_payload']['room_dimensions'][0]['length_m']);
        self::assertSame(1.58, $takeoffsByKey['rough.walls']['normalized_payload']['room_dimensions'][0]['width_m']);
        self::assertCount(1, array_filter(
            $takeoffsByKey['rough.walls']['source_refs'],
            static fn (array $sourceRef): bool => ($sourceRef['excerpt'] ?? null) === '3255'
        ));
        self::assertCount(1, array_filter(
            $takeoffsByKey['rough.walls']['source_refs'],
            static fn (array $sourceRef): bool => ($sourceRef['excerpt'] ?? null) === '1580'
        ));
    }

    public function test_door_schedule_height_is_not_used_as_room_height(): void
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
                        'Дверь ДП-1 B=900 H=2100',
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

        self::assertNotContains('height', array_column($result->elements, 'type'));
        self::assertSame(0, $result->summary['height_count'] ?? null);
        self::assertNull($result->summary['detected_height_m'] ?? null);
    }

    public function test_extracts_specification_rows_as_quantity_takeoffs(): void
    {
        $recognition = new OcrRecognitionResult(
            provider: 'test',
            model: 'spreadsheet',
            pages: [
                new OcrPageResult(
                    pageNumber: 1,
                    text: implode("\n", [
                        'Спецификация оборудования',
                        'Поз. Наименование Ед. Количество',
                        '1 Светильник светодиодный шт 42',
                        '2 Радиатор стальной шт 8',
                        '3 Труба отопления м 36',
                        '4 Дверь ДП-1 шт 5',
                    ]),
                    confidence: 0.99
                ),
            ]
        );

        $result = (new RuleBasedDrawingAnalysisProvider())->analyze(
            documentId: 10,
            filename: 'spec.xlsx',
            recognition: $recognition
        );
        $takeoffsByKey = [];

        foreach ($result->takeoffs as $takeoff) {
            $payload = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];
            $takeoffsByKey[(string) ($payload['quantity_key'] ?? '')] = $takeoff;
        }

        self::assertSame('specification', $result->summary['document_profile']['document_role'] ?? null);
        self::assertSame('specification', $result->summary['page_profiles'][0]['page_role'] ?? null);
        self::assertArrayHasKey('warehouse.lighting', $takeoffsByKey);
        self::assertArrayHasKey('heating.radiators', $takeoffsByKey);
        self::assertArrayHasKey('heating.pipe', $takeoffsByKey);
        self::assertArrayHasKey('openings.doors', $takeoffsByKey);
        self::assertSame(42.0, $takeoffsByKey['warehouse.lighting']['quantity']);
        self::assertSame('specification_quantity', $takeoffsByKey['warehouse.lighting']['scope_key']);
        self::assertSame('specification', $takeoffsByKey['warehouse.lighting']['normalized_payload']['source']);
    }

    public function test_extracts_work_volume_statement_rows_without_turning_unknown_rows_into_takeoffs(): void
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
                        '1 Обратная засыпка пазух м3 42',
                        '2 Окраска стен м2 180',
                        '3 Авторский надзор компл 1',
                    ]),
                    confidence: 0.94
                ),
            ]
        );

        $result = (new RuleBasedDrawingAnalysisProvider())->analyze(
            documentId: 10,
            filename: 'Ведомость объемов работ.pdf',
            recognition: $recognition
        );
        $takeoffsByKey = [];

        foreach ($result->takeoffs as $takeoff) {
            $payload = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];
            $takeoffsByKey[(string) ($payload['quantity_key'] ?? '')] = $takeoff;
        }

        $unmappedRows = array_values(array_filter(
            $result->elements,
            static fn (array $element): bool => ($element['type'] ?? null) === 'unmapped_specification_row'
        ));

        self::assertSame('work_volume_statement', $result->summary['document_profile']['document_role'] ?? null);
        self::assertSame('work_volume_statement', $result->summary['page_profiles'][0]['page_role'] ?? null);
        self::assertArrayHasKey('earth.backfill', $takeoffsByKey);
        self::assertArrayHasKey('finish.paint', $takeoffsByKey);
        self::assertSame('work_volume_statement', $takeoffsByKey['earth.backfill']['normalized_payload']['source']);
        self::assertSame('earthworks', $takeoffsByKey['earth.backfill']['normalized_payload']['scope_type']);
        self::assertSame(42.0, $takeoffsByKey['earth.backfill']['quantity']);
        self::assertSame(180.0, $takeoffsByKey['finish.paint']['quantity']);
        self::assertCount(1, $unmappedRows);
        self::assertSame('Авторский надзор', $unmappedRows[0]['label']);
        self::assertSame('quantity_row_not_mapped', $unmappedRows[0]['normalized_payload']['reason']);
        self::assertArrayNotHasKey('', $takeoffsByKey);
    }
}
