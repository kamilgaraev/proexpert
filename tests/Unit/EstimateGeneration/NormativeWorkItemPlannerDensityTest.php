<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;
use PHPUnit\Framework\TestCase;

class NormativeWorkItemPlannerDensityTest extends TestCase
{
    public function test_planner_keeps_work_composition_inside_priced_items_without_operation_rows(): void
    {
        $localEstimate = $this->localEstimate('foundation', 'Фундамент', 'foundation', 12);
        $items = $this->planner()->build($localEstimate, $localEstimate['sections'][0], [
            'document_context' => [
                'quantity_takeoffs' => [[
                    'quantity_key' => 'foundation.concrete',
                    'name' => 'Бетонирование фундаментов по ВОР',
                    'unit' => 'м3',
                    'quantity' => 32.5,
                    'source_refs' => [[
                        'type' => 'document',
                        'filename' => 'ВОР.pdf',
                        'page_number' => 1,
                    ]],
                ]],
            ],
        ]);
        $pricedItems = $this->pricedItems($items);

        self::assertNotEmpty($pricedItems);
        self::assertSame(count($pricedItems), count($items));
        self::assertNotContains('operation', array_column($items, 'item_type'));
        self::assertSame(count($pricedItems), count(array_unique(array_column($pricedItems, 'normative_search_key'))));

        foreach ($pricedItems as $item) {
            self::assertSame([], $item['materials']);
            self::assertSame([], $item['labor']);
            self::assertSame([], $item['machinery']);
            self::assertNotEmpty($item['work_composition']);
            self::assertContains('normative_required', $item['validation_flags']);
            self::assertNull($item['price_source']);
        }

        self::assertNotContains('quantity_review_required', $pricedItems[0]['validation_flags']);
        self::assertSame('document_quantity', $pricedItems[0]['metadata']['quantity_source']);
    }

    public function test_package_key_selects_specific_normative_intents_even_when_scope_is_broad(): void
    {
        $analysis = [
            'document_context' => [
                'quantity_takeoffs' => [
                    [
                        'quantity_key' => 'electrical.power_lines',
                        'name' => 'Длина силовых линий по ведомости',
                        'unit' => 'м',
                        'quantity' => 120,
                    ],
                    [
                        'quantity_key' => 'plumbing.pipe',
                        'name' => 'Длина труб водоснабжения по ведомости',
                        'unit' => 'м',
                        'quantity' => 64,
                    ],
                ],
            ],
        ];
        $electricalLocal = $this->localEstimate('electrical', 'Электрика', 'engineering', 12);
        $plumbingLocal = $this->localEstimate('plumbing', 'Водоснабжение', 'engineering', 12);

        $planner = $this->planner();
        $electrical = $this->pricedItems($planner->build(
            $electricalLocal,
            $electricalLocal['sections'][0],
            $analysis
        ));
        $plumbing = $this->pricedItems($planner->build(
            $plumbingLocal,
            $plumbingLocal['sections'][0],
            $analysis
        ));

        self::assertContains('electrical', array_column($electrical, 'work_category'));
        self::assertContains('plumbing', array_column($plumbing, 'work_category'));
        self::assertNotSame(
            array_column($electrical, 'normative_search_key'),
            array_column($plumbing, 'normative_search_key')
        );
    }

    public function test_same_quantity_key_takeoffs_are_aggregated_with_all_source_refs(): void
    {
        $localEstimate = $this->localEstimate('earthworks', 'Земляные работы', 'foundation', 4);

        $items = $this->pricedItems($this->planner()->build($localEstimate, $localEstimate['sections'][0], [
            'document_context' => [
                'quantity_takeoffs' => [[
                    'quantity_key' => 'earth.backfill',
                    'name' => 'Обратная засыпка по ведомости, строка 1',
                    'unit' => 'м3',
                    'quantity' => 10.5,
                    'source_refs' => [[
                        'type' => 'document',
                        'filename' => 'ВОР.pdf',
                        'page_number' => 1,
                    ]],
                ], [
                    'quantity_key' => 'earth.backfill',
                    'name' => 'Обратная засыпка по ведомости, строка 2',
                    'unit' => 'м3',
                    'quantity' => 4.25,
                    'source_refs' => [[
                        'type' => 'document',
                        'filename' => 'ВОР.pdf',
                        'page_number' => 2,
                    ]],
                ]],
            ],
        ]));
        $backfill = array_values(array_filter(
            $items,
            static fn (array $item): bool => ($item['quantity_formula'] ?? null) === 'earth.backfill'
        ))[0] ?? null;

        self::assertIsArray($backfill);
        self::assertSame(14.75, (float) $backfill['quantity']);
        self::assertCount(2, $backfill['source_refs']);
        self::assertStringContainsString('строка 1', $backfill['quantity_basis']);
        self::assertStringContainsString('строка 2', $backfill['quantity_basis']);
    }

    public function test_sewerage_package_uses_specific_normative_intents_instead_of_generic_complex_work(): void
    {
        $localEstimate = $this->localEstimate('sewerage', 'Канализация', 'engineering', 12);

        $pricedItems = $this->pricedItems($this->planner()->build($localEstimate, $localEstimate['sections'][0], [
            'document_context' => [
                'quantity_takeoffs' => [
                    [
                        'quantity_key' => 'sewerage.pipe',
                        'name' => 'Длина труб канализации по ведомости',
                        'unit' => 'м',
                        'quantity' => 42,
                    ],
                    [
                        'quantity_key' => 'sewerage.outlets',
                        'name' => 'Выпуски канализации по спецификации',
                        'unit' => 'шт',
                        'quantity' => 4,
                    ],
                ],
            ],
        ]));
        $names = array_column($pricedItems, 'name');

        self::assertContains('Прокладка труб канализации', $names);
        self::assertContains('Монтаж канализационных выпусков', $names);
        self::assertNotContains('Комплекс строительных работ', $names);
        self::assertNotContains('Прокладка магистральных кабелей', $names);
        self::assertNotContains('site.setup', array_column($pricedItems, 'quantity_formula'));
    }

    public function test_unknown_engineering_package_does_not_fall_back_to_electrical_or_generic_work(): void
    {
        $localEstimate = $this->localEstimate('unclassified_engineering', 'Инженерные системы', 'engineering', 12);

        $items = $this->planner()->build($localEstimate, $localEstimate['sections'][0], [
            'document_context' => [
                'facts_summary' => [
                    'total_area_m2' => 214,
                ],
            ],
        ]);

        self::assertSame([], $items);
    }

    public function test_total_area_does_not_create_ventilation_or_fire_safety_without_explicit_takeoff(): void
    {
        $planner = $this->planner();
        $analysis = [
            'document_context' => [
                'facts_summary' => [
                    'total_area_m2' => 214,
                ],
            ],
        ];

        foreach ([
            'ventilation' => ['Вентиляция', 'engineering'],
            'fire_safety' => ['Пожарная безопасность', 'engineering'],
        ] as $packageKey => [$title, $scopeType]) {
            $localEstimate = $this->localEstimate($packageKey, $title, $scopeType, 12);

            self::assertSame([], $planner->build($localEstimate, $localEstimate['sections'][0], $analysis), $packageKey);
        }
    }

    public function test_unknown_custom_scope_does_not_create_generic_complex_work(): void
    {
        $localEstimate = $this->localEstimate('local-custom', 'Основные строительные работы', 'custom', 12);

        $items = $this->planner()->build($localEstimate, $localEstimate['sections'][0], [
            'document_context' => [
                'quantity_takeoffs' => [
                    [
                        'quantity_key' => 'stairs.flights',
                        'name' => 'Лестничные марши по спецификации',
                        'unit' => 'м2',
                        'quantity' => 18,
                    ],
                    [
                        'quantity_key' => 'stairs.railings',
                        'name' => 'Ограждение лестниц по спецификации',
                        'unit' => 'м',
                        'quantity' => 22,
                    ],
                ],
            ],
        ]);

        self::assertSame([], $items);
    }

    public function test_stairs_package_uses_specific_normative_intents_instead_of_generic_complex_work(): void
    {
        $localEstimate = $this->localEstimate('stairs', 'Лестницы', 'stairs', 8);

        $items = $this->planner()->build($localEstimate, $localEstimate['sections'][0], [
            'document_context' => [
                'quantity_takeoffs' => [
                    [
                        'quantity_key' => 'stairs.flights',
                        'name' => 'Лестничные марши по спецификации',
                        'unit' => 'м2',
                        'quantity' => 18,
                    ],
                    [
                        'quantity_key' => 'stairs.railings',
                        'name' => 'Ограждение лестниц по спецификации',
                        'unit' => 'м',
                        'quantity' => 22,
                    ],
                ],
            ],
        ]);
        $pricedItems = $this->pricedItems($items);
        $names = array_column($pricedItems, 'name');

        self::assertContains('Устройство лестничных маршей', $names);
        self::assertContains('Ограждение лестниц', $names);
        self::assertNotContains('Комплекс строительных работ', $names);
        self::assertNotContains('site.setup', array_column($pricedItems, 'quantity_formula'));
        self::assertSame(count($pricedItems), count($items));
        self::assertNotContains('operation', array_column($items, 'item_type'));
    }

    public function test_known_planner_package_keys_do_not_generate_planner_fallback_priced_items(): void
    {
        $planner = $this->planner();

        foreach ($this->knownPackageScopes() as $packageKey => $scopeType) {
            $localEstimate = $this->localEstimate($packageKey, $packageKey, $scopeType, 4);
            $items = $planner->build($localEstimate, $localEstimate['sections'][0], $this->analysisForPackage($packageKey));
            $pricedItems = $this->pricedItems($items);
            $names = array_column($pricedItems, 'name');

            self::assertSame(
                [],
                array_values(array_diff(array_column($items, 'item_type'), ['priced_work', 'quantity_review'])),
                $packageKey
            );
            self::assertNotContains('operation', array_column($items, 'item_type'), $packageKey);
            self::assertNotContains('custom', array_column($pricedItems, 'work_category'), $packageKey);
            self::assertNotContains('Комплекс строительных работ', $names, $packageKey);
            self::assertNotContains(
                'planner_fallback',
                array_map(
                    static fn (array $item): string => (string) ($item['metadata']['quantity_source'] ?? ''),
                    $pricedItems
                ),
                $packageKey
            );
        }
    }

    public function test_known_planner_package_keys_generate_priced_items_from_confirmed_takeoffs_without_fallback(): void
    {
        $planner = $this->planner();

        foreach ($this->knownPackageScopes() as $packageKey => $scopeType) {
            $localEstimate = $this->localEstimate($packageKey, $packageKey, $scopeType, 4);
            $items = $planner->build($localEstimate, $localEstimate['sections'][0], [
                'document_context' => [
                    'quantity_takeoffs' => [
                        $this->confirmedTakeoffForPackage($packageKey),
                    ],
                ],
            ]);
            $pricedItems = $this->pricedItems($items);

            self::assertNotEmpty($pricedItems, $packageKey);
            self::assertNotContains(
                'planner_fallback',
                array_map(
                    static fn (array $item): string => (string) ($item['metadata']['quantity_source'] ?? ''),
                    $pricedItems
                ),
                $packageKey
            );

            foreach ($pricedItems as $pricedItem) {
                self::assertNotContains('quantity_review_required', $pricedItem['validation_flags'], $packageKey);
            }
        }
    }

    public function test_known_planner_package_keys_do_not_expose_generic_work_composition(): void
    {
        $planner = $this->planner();
        $genericOperations = [
            'Подготовка фронта работ',
            'Поставка материалов',
            'Основной монтаж',
            'Крепление',
            'Контроль качества',
        ];

        foreach ($this->knownPackageScopes() as $packageKey => $scopeType) {
            $localEstimate = $this->localEstimate($packageKey, $packageKey, $scopeType, 4);
            $items = $this->pricedItems($planner->build($localEstimate, $localEstimate['sections'][0], [
                'document_context' => [
                    'quantity_takeoffs' => [
                        $this->confirmedTakeoffForPackage($packageKey),
                    ],
                ],
            ]));

            self::assertNotEmpty($items, $packageKey);

            foreach ($items as $item) {
                self::assertNotSame(
                    $genericOperations,
                    array_values($item['work_composition'] ?? []),
                    $packageKey . ':' . ($item['quantity_formula'] ?? $item['key'])
                );
            }
        }
    }

    public function test_source_backed_package_takeoff_suppresses_duplicate_scope_inference_for_same_quantity_key(): void
    {
        $localEstimate = $this->localEstimate('finish_finishing', 'Finish', 'finishing', 8);

        $items = $this->pricedItems($this->planner()->build($localEstimate, $localEstimate['sections'][0], [
            'document_context' => [
                'quantity_takeoffs' => [[
                    'scope_key' => 'floor_finish_area',
                    'quantity_key' => 'finish.floor',
                    'name' => 'Floor finish area from plan',
                    'unit' => 'm2',
                    'quantity' => 87.14,
                    'source_refs' => [[
                        'type' => 'drawing',
                        'filename' => 'floor-plan.pdf',
                        'page_number' => 1,
                    ]],
                    'normalized_payload' => [
                        'quantity_key' => 'finish.floor',
                    ],
                ]],
                'scope_inferences' => [[
                    'inference_type' => 'drawing_takeoff',
                    'scope_type' => 'finishing',
                    'title' => 'Floor finish area from scope inference',
                    'confidence' => 0.82,
                    'source_refs' => [[
                        'type' => 'drawing',
                        'filename' => 'floor-plan.pdf',
                        'page_number' => 1,
                    ]],
                    'normalized_payload' => [
                        'quantity_key' => 'finish.floor',
                        'quantity_value' => 87.14,
                        'unit' => 'm2',
                    ],
                ]],
            ],
        ]));
        $finishFloorItems = array_values(array_filter(
            $items,
            static fn (array $item): bool => ($item['quantity_formula'] ?? null) === 'finish.floor'
        ));

        self::assertCount(1, $finishFloorItems);
        self::assertSame('normative_intent_catalog', $finishFloorItems[0]['metadata']['generation_source'] ?? null);
        self::assertSame(87.14, (float) $finishFloorItems[0]['quantity']);
    }

    public function test_mixed_office_warehouse_uses_document_scope_and_flat_roof_quantities(): void
    {
        $analysis = [
            'document_context' => [
                'facts' => [
                    [
                        'label' => 'Кровля',
                        'value_text' => 'Плоская кровля',
                    ],
                ],
                'quantity_takeoffs' => [
                    [
                        'scope_key' => 'industrial_floor',
                        'quantity_key' => 'warehouse.floor_concrete',
                        'name' => 'Объем бетона плиты пола по чертежу',
                        'quantity' => 75.6,
                        'unit' => 'м3',
                        'source_refs' => [
                            [
                                'type' => 'drawing',
                                'filename' => 'АР-1.pdf',
                                'page_number' => 4,
                            ],
                        ],
                    ],
                    [
                        'scope_key' => 'roof',
                        'quantity_key' => 'roof.flat_area',
                        'name' => 'Площадь плоской кровли по экспликации',
                        'quantity' => 390,
                        'unit' => 'м2',
                    ],
                ],
            ],
        ];
        $floorLocal = $this->localEstimate('industrial_floor', 'Промышленный пол', 'slabs', 20);
        $roofLocal = $this->localEstimate('roof', 'Кровля', 'roof', 20);

        $planner = $this->planner();
        $industrialFloor = $this->pricedItems($planner->build(
            $floorLocal,
            $floorLocal['sections'][0],
            $analysis
        ));
        $roof = $this->pricedItems($planner->build(
            $roofLocal,
            $roofLocal['sections'][0],
            $analysis
        ));

        self::assertCount(1, $industrialFloor);
        self::assertCount(4, $roof);
        self::assertSame('warehouse.floor_concrete', $industrialFloor[0]['quantity_formula']);
        self::assertSame(75.6, (float) $industrialFloor[0]['quantity']);
        self::assertContains('roof.flat_area', array_column($roof, 'quantity_formula'));
        self::assertNotContains('roof.area', array_column($roof, 'quantity_formula'));
    }

    public function test_drawing_takeoff_review_required_becomes_visible_review_item_until_confirmed(): void
    {
        $localEstimate = $this->localEstimate('rough_finishing', 'Черновая отделка', 'finishing', 6);
        $items = $this->planner()->build(
            $localEstimate,
            $localEstimate['sections'][0],
            [
                'document_context' => [
                    'quantity_takeoffs' => [[
                        'scope_key' => 'wall_finish_area',
                        'name' => 'Расчетная площадь стен по планировке',
                        'unit' => 'м2',
                        'quantity' => 220.5,
                        'confidence' => 0.68,
                        'source_refs' => [[
                            'type' => 'drawing',
                            'document_id' => 10,
                            'filename' => 'flat-plan.png',
                            'page_number' => 1,
                        ]],
                        'normalized_payload' => [
                            'quantity_key' => 'rough.walls',
                            'review_required' => true,
                        ],
                    ]],
                ],
            ]
        );

        self::assertCount(1, $items);
        self::assertSame('quantity_review', $items[0]['item_type']);
        self::assertSame('rough.walls', $items[0]['quantity_formula']);
        self::assertSame(220.5, (float) $items[0]['quantity']);
        self::assertSame('not_applicable', $items[0]['pricing_status']);
        self::assertSame('quantity_review_required', $items[0]['pricing_blocker']);
        self::assertContains('quantity_review_required', $items[0]['validation_flags']);
        self::assertSame('rough.walls', $items[0]['metadata']['quantity_key'] ?? null);
    }

    public function test_summary_area_without_source_refs_requires_quantity_review(): void
    {
        $localEstimate = $this->localEstimate('rough_finishing', 'Черновая отделка', 'finishing', 6);
        $items = $this->planner()->build(
            $localEstimate,
            $localEstimate['sections'][0],
            [
                'document_context' => [
                    'facts_summary' => [
                        'total_area_m2' => 87.14,
                    ],
                ],
            ]
        );

        self::assertCount(1, $items);
        self::assertSame('quantity_review', $items[0]['item_type']);
        self::assertSame('rough.floor', $items[0]['quantity_formula']);
        self::assertSame(87.14, (float) $items[0]['quantity']);
        self::assertSame([], $items[0]['source_refs']);
        self::assertSame('facts_summary_area', $items[0]['metadata']['quantity_source']);
        self::assertContains('quantity_review_required', $items[0]['validation_flags']);
        self::assertSame('quantity_review_required', $items[0]['pricing_blocker']);
    }

    public function test_floor_plan_baseboard_length_becomes_visible_review_item_until_confirmed(): void
    {
        $localEstimate = $this->localEstimate('finish_finishing', 'Чистовая отделка', 'finishing', 6);
        $items = $this->planner()->build(
            $localEstimate,
            $localEstimate['sections'][0],
            [
                'document_context' => [
                    'quantity_takeoffs' => [[
                        'scope_key' => 'skirting_length',
                        'name' => 'Расчетная длина плинтуса по планировке',
                        'unit' => 'м',
                        'quantity' => 77.0,
                        'confidence' => 0.66,
                        'source_refs' => [[
                            'type' => 'drawing',
                            'document_id' => 10,
                            'filename' => 'flat-plan.png',
                            'page_number' => 1,
                        ]],
                        'normalized_payload' => [
                            'quantity_key' => 'finish.baseboard',
                            'review_required' => true,
                        ],
                    ]],
                ],
            ]
        );

        self::assertCount(1, $items);
        self::assertSame('quantity_review', $items[0]['item_type']);
        self::assertSame('finish.baseboard', $items[0]['quantity_formula']);
        self::assertSame(77.0, (float) $items[0]['quantity']);
        self::assertSame('м', $items[0]['unit']);
        self::assertSame('quantity_review_required', $items[0]['pricing_blocker']);
        self::assertContains('quantity_review_required', $items[0]['validation_flags']);
        self::assertSame('finish.baseboard', $items[0]['metadata']['quantity_key'] ?? null);
    }

    public function test_confirmed_floor_plan_wall_and_wet_zone_takeoffs_feed_matching_normative_intents(): void
    {
        $analysis = [
            'document_context' => [
                'quantity_takeoffs' => [
                    [
                        'scope_key' => 'paint_area',
                        'name' => 'Wall paint area from floor plan',
                        'unit' => 'м2',
                        'quantity' => 312.4,
                        'confidence' => 0.69,
                        'source_refs' => [[
                            'type' => 'drawing',
                            'filename' => 'flat-plan.png',
                            'page_number' => 1,
                        ]],
                        'normalized_payload' => [
                            'quantity_key' => 'finish.paint',
                            'review_required' => false,
                        ],
                    ],
                    [
                        'scope_key' => 'wet_zone_tile_area',
                        'name' => 'Wet zone tile area from floor plan',
                        'unit' => 'м2',
                        'quantity' => 54.2,
                        'confidence' => 0.67,
                        'source_refs' => [[
                            'type' => 'drawing',
                            'filename' => 'flat-plan.png',
                            'page_number' => 1,
                        ]],
                        'normalized_payload' => [
                            'quantity_key' => 'sanitary.tile',
                            'review_required' => false,
                        ],
                    ],
                ],
            ],
        ];
        $finishLocal = $this->localEstimate('finish_finishing', 'Чистовая отделка', 'finishing', 6);
        $plumbingLocal = $this->localEstimate('plumbing', 'Водоснабжение', 'plumbing', 6);
        $planner = $this->planner();

        $finishItems = $this->pricedItems($planner->build($finishLocal, $finishLocal['sections'][0], $analysis));
        $plumbingItems = $this->pricedItems($planner->build($plumbingLocal, $plumbingLocal['sections'][0], $analysis));
        $paintItem = array_values(array_filter(
            $finishItems,
            static fn (array $item): bool => ($item['quantity_formula'] ?? null) === 'finish.paint'
        ))[0] ?? null;
        $tileItem = array_values(array_filter(
            $plumbingItems,
            static fn (array $item): bool => ($item['quantity_formula'] ?? null) === 'sanitary.tile'
        ))[0] ?? null;

        self::assertIsArray($paintItem);
        self::assertSame(312.4, (float) $paintItem['quantity']);
        self::assertNotContains('quantity_review_required', $paintItem['validation_flags']);
        self::assertIsArray($tileItem);
        self::assertSame(54.2, (float) $tileItem['quantity']);
        self::assertNotContains('quantity_review_required', $tileItem['validation_flags']);
        self::assertNotSame('компл', $tileItem['unit']);
    }

    public function test_floor_plan_rough_and_finish_packages_do_not_duplicate_finish_intents(): void
    {
        $analysis = [
            'document_context' => [
                'quantity_takeoffs' => [
                    $this->confirmedTakeoff('rough.floor', 87.14, 'м2'),
                    $this->confirmedTakeoff('rough.walls', 235.28, 'м2'),
                    $this->confirmedTakeoff('finish.floor', 87.14, 'м2'),
                    $this->confirmedTakeoff('finish.paint', 235.28, 'м2'),
                    $this->confirmedTakeoff('office.ceiling', 87.14, 'м2'),
                ],
            ],
        ];
        $roughLocal = $this->localEstimate('rough_finishing', 'Черновая отделка', 'finishing', 6);
        $finishLocal = $this->localEstimate('finish_finishing', 'Чистовая отделка', 'finishing', 6);
        $planner = $this->planner();

        $roughItems = $this->pricedItems($planner->build($roughLocal, $roughLocal['sections'][0], $analysis));
        $finishItems = $this->pricedItems($planner->build($finishLocal, $finishLocal['sections'][0], $analysis));
        $roughFormulas = array_column($roughItems, 'quantity_formula');
        $finishFormulas = array_column($finishItems, 'quantity_formula');

        self::assertSame(['rough.floor', 'rough.walls'], $roughFormulas);
        self::assertSame(['finish.floor', 'finish.paint', 'office.ceiling'], $finishFormulas);
        self::assertSame([], array_values(array_intersect($roughFormulas, $finishFormulas)));
    }

    public function test_engineering_takeoff_scope_maps_to_matching_heating_quantity_key(): void
    {
        $localEstimate = $this->localEstimate('heating', 'Отопление', 'engineering', 12);

        $items = $this->pricedItems($this->planner()->build(
            $localEstimate,
            $localEstimate['sections'][0],
            [
                'document_context' => [
                    'quantity_takeoffs' => [[
                        'scope_key' => 'heating_route_length',
                        'name' => 'Длина трасс отопления по планировке',
                        'unit' => 'м',
                        'quantity' => 38.2,
                        'source_refs' => [[
                            'type' => 'drawing',
                            'filename' => 'heating-plan.pdf',
                            'page_number' => 1,
                        ]],
                    ]],
                ],
            ]
        ));
        $pipeItem = array_values(array_filter(
            $items,
            static fn (array $item): bool => ($item['quantity_formula'] ?? null) === 'heating.pipe'
        ))[0] ?? null;

        self::assertIsArray($pipeItem);
        self::assertSame(38.2, (float) $pipeItem['quantity']);
        self::assertSame('м', $pipeItem['unit']);
    }

    public function test_persisted_work_volume_statement_inference_creates_evidence_backed_single_work_item(): void
    {
        $localEstimate = $this->localEstimate('custom-earthworks', 'Земляные работы', 'earthworks', 1);

        $items = $this->pricedItems($this->planner()->build(
            $localEstimate,
            $localEstimate['sections'][0],
            [
                'document_context' => [
                    'scope_inferences' => [[
                        'inference_type' => 'specification_takeoff',
                        'title' => 'Земляные работы',
                        'source_refs' => [[
                            'type' => 'drawing',
                            'filename' => 'Ведомость объемов работ.pdf',
                            'page_number' => 1,
                        ]],
                        'work_intent' => [
                            'scope' => 'earthworks',
                            'quantity_key' => 'earth.backfill',
                            'source' => 'work_volume_statement',
                        ],
                        'normative_basis' => [
                            'quantity_value' => 42.0,
                            'unit' => 'м3',
                        ],
                        'confidence' => 0.84,
                        'review_required' => false,
                    ]],
                ],
            ]
        ));

        self::assertCount(1, $items);
        self::assertSame('earth.backfill', $items[0]['quantity_formula']);
        self::assertSame(42.0, (float) $items[0]['quantity']);
        self::assertSame('м3', $items[0]['unit']);
        self::assertSame('scope_inference', $items[0]['metadata']['generation_source']);
        self::assertSame('work_volume_statement', $items[0]['metadata']['quantity_source']);
        self::assertNotEmpty($items[0]['source_refs']);
    }

    public function test_scope_inference_without_own_source_refs_requires_quantity_review_even_when_section_has_sources(): void
    {
        $localEstimate = $this->localEstimate('custom-earthworks', 'Земляные работы', 'earthworks', 1);
        $localEstimate['source_refs'] = [[
            'type' => 'document',
            'filename' => 'Общий архив.pdf',
            'page_number' => 1,
        ]];
        $localEstimate['sections'][0]['source_refs'] = $localEstimate['source_refs'];

        $items = $this->planner()->build(
            $localEstimate,
            $localEstimate['sections'][0],
            [
                'document_context' => [
                    'scope_inferences' => [[
                        'inference_type' => 'specification_takeoff',
                        'title' => 'Обратная засыпка пазух',
                        'scope_type' => 'earthworks',
                        'work_intent' => [
                            'scope' => 'earthworks',
                            'quantity_key' => 'earth.backfill',
                            'source' => 'work_volume_statement',
                        ],
                        'normative_basis' => [
                            'quantity_value' => 42.0,
                            'unit' => 'м3',
                        ],
                        'confidence' => 0.84,
                        'review_required' => false,
                    ]],
                ],
            ]
        );

        self::assertCount(1, $items);
        self::assertSame('quantity_review', $items[0]['item_type']);
        self::assertSame('earth.backfill', $items[0]['quantity_formula']);
        self::assertSame([], $items[0]['source_refs']);
        self::assertContains('quantity_review_required', $items[0]['validation_flags']);
    }

    public function test_unmapped_review_package_ignores_regular_scope_inferences(): void
    {
        $localEstimate = $this->localEstimate('unmapped_quantity_rows', 'Позиции для разбора', 'custom', 1);

        $items = $this->planner()->build(
            $localEstimate,
            $localEstimate['sections'][0],
            [
                'document_context' => [
                    'scope_inferences' => [
                        [
                            'inference_type' => 'work_volume_takeoff',
                            'scope_type' => 'earthworks',
                            'title' => 'Обратная засыпка пазух',
                            'source_ref' => ['type' => 'drawing', 'document_id' => 80, 'page_number' => 1],
                            'source_refs' => [['type' => 'drawing', 'document_id' => 80, 'page_number' => 1]],
                            'normalized_payload' => [
                                'quantity_key' => 'earth.backfill',
                                'quantity_value' => 42.0,
                                'unit' => 'м3',
                                'source' => 'work_volume_statement',
                            ],
                            'confidence' => 0.84,
                            'review_required' => false,
                        ],
                        [
                            'inference_type' => 'unmapped_quantity_row',
                            'scope_type' => 'custom',
                            'title' => 'Авторский надзор',
                            'source_ref' => ['type' => 'drawing', 'document_id' => 80, 'page_number' => 1],
                            'source_refs' => [['type' => 'drawing', 'document_id' => 80, 'page_number' => 1]],
                            'normalized_payload' => [
                                'quantity_key' => 'unmapped.abc123',
                                'quantity_value' => 1.0,
                                'unit' => 'компл',
                                'source' => 'work_volume_statement',
                                'reason' => 'quantity_row_not_mapped',
                            ],
                            'confidence' => 0.72,
                            'review_required' => true,
                        ],
                    ],
                    'quantity_takeoffs' => [],
                ],
            ]
        );

        self::assertSame(['Авторский надзор'], array_column($items, 'name'));
        self::assertSame(['quantity_review'], array_column($items, 'item_type'));
        self::assertSame(['unmapped.abc123'], array_column($items, 'quantity_formula'));
    }

    public function test_optional_site_package_without_document_quantity_is_not_expanded_from_catalog_fallback(): void
    {
        $localEstimate = $this->localEstimate('external_networks', 'External networks', 'site', 12);

        $items = $this->planner()->build($localEstimate, $localEstimate['sections'][0], [
            'document_context' => [
                'facts_summary' => [
                    'total_area_m2' => 214,
                ],
            ],
        ]);

        self::assertSame([], $items);
    }

    public function test_optional_site_package_uses_document_quantity_takeoff_when_available(): void
    {
        $localEstimate = $this->localEstimate('external_networks', 'External networks', 'site', 12);

        $items = $this->planner()->build($localEstimate, $localEstimate['sections'][0], [
            'document_context' => [
                'quantity_takeoffs' => [[
                    'scope_key' => 'external_networks',
                    'quantity_key' => 'networks.external',
                    'name' => 'External utility route length from plan',
                    'unit' => 'm',
                    'quantity' => 42.5,
                    'source_refs' => [[
                        'type' => 'drawing',
                        'filename' => 'site-plan.pdf',
                        'page_number' => 2,
                    ]],
                ]],
            ],
        ]);
        $pricedItems = $this->pricedItems($items);

        self::assertCount(1, $pricedItems);
        self::assertSame('networks.external', $pricedItems[0]['quantity_formula']);
        self::assertSame(42.5, (float) $pricedItems[0]['quantity']);
        self::assertNotContains('quantity_review_required', $pricedItems[0]['validation_flags']);
        self::assertSame($pricedItems, $items);
        self::assertNotContains('operation', array_column($items, 'item_type'));
    }

    /**
     * @return array<string, mixed>
     */
    private function localEstimate(string $key, string $title, string $scopeType, int $targetMin): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'scope_type' => $scopeType,
            'source_refs' => [],
            'target_items_min' => $targetMin,
            'target_items_max' => $targetMin + 20,
            'sections' => [[
                'key' => $key . '-section',
                'title' => $title,
                'construction_part' => $scopeType,
                'source_refs' => [],
            ]],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function pricedItems(array $items): array
    {
        return array_values(array_filter($items, static fn (array $item): bool => ($item['item_type'] ?? null) === 'priced_work'));
    }

    private function planner(): NormativeWorkItemPlannerService
    {
        return new NormativeWorkItemPlannerService(
            new ProjectDocumentNormativeReferenceExtractor(),
            new EstimatorScopeInferenceService(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function knownPackageScopes(): array
    {
        return [
            'preconstruction' => 'site',
            'site_preparation' => 'site',
            'earthworks' => 'foundation',
            'foundation' => 'foundation',
            'foundations' => 'foundation',
            'walls' => 'walls',
            'office_partitions' => 'walls',
            'slabs' => 'slabs',
            'industrial_floor' => 'slabs',
            'stairs' => 'stairs',
            'metal_frame' => 'structural',
            'envelope' => 'facade',
            'facade' => 'facade',
            'roof' => 'roof',
            'openings' => 'openings',
            'gates' => 'openings',
            'entrance_group' => 'openings',
            'electrical' => 'electrical',
            'power_supply' => 'electrical',
            'lighting' => 'electrical',
            'low_current' => 'electrical',
            'server_room' => 'electrical',
            'plumbing' => 'plumbing',
            'water_sewerage' => 'plumbing',
            'sanitary_rooms' => 'plumbing',
            'sewerage' => 'sewerage',
            'heating' => 'heating',
            'ventilation' => 'ventilation',
            'fire_safety' => 'engineering',
            'rough_finishing' => 'finishing',
            'finish_finishing' => 'finishing',
            'office_finishing' => 'finishing',
            'external_networks' => 'site',
            'siteworks' => 'site',
            'roads' => 'site',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmedTakeoffForPackage(string $packageKey): array
    {
        [$quantityKey, $unit, $quantity] = match ($packageKey) {
            'preconstruction', 'site_preparation' => ['site.setup', 'компл', 1],
            'earthworks' => ['earth.trench', 'м3', 120],
            'foundation', 'foundations' => ['foundation.concrete', 'м3', 32.5],
            'walls' => ['walls.external_volume', 'м3', 38],
            'office_partitions' => ['office.partitions', 'м2', 84],
            'slabs', 'industrial_floor' => ['warehouse.floor_concrete', 'м3', 75.6],
            'stairs' => ['stairs.flights', 'м2', 18],
            'metal_frame' => ['warehouse.columns', 'т', 14.2],
            'envelope' => ['warehouse.wall_panels', 'м2', 310],
            'facade' => ['facade.area', 'м2', 280],
            'roof' => ['roof.area', 'м2', 194],
            'openings' => ['openings.windows', 'шт', 12],
            'gates', 'entrance_group' => ['warehouse.gates', 'шт', 3],
            'electrical', 'power_supply' => ['electrical.power_lines', 'м', 120],
            'lighting' => ['warehouse.lighting', 'шт', 42],
            'low_current', 'server_room' => ['warehouse.low_current', 'м', 95],
            'plumbing', 'water_supply', 'water_sewerage', 'sanitary_rooms' => ['plumbing.pipe', 'м', 64],
            'sewerage' => ['sewerage.pipe', 'м', 42],
            'heating' => ['heating.pipe', 'м', 38.2],
            'ventilation' => ['ventilation.air_exchange', 'м2', 214],
            'fire_safety' => ['warehouse.fire', 'м2', 214],
            'rough_finishing' => ['rough.floor', 'м2', 87.14],
            'finish_finishing', 'office_finishing' => ['finish.floor', 'м2', 87.14],
            'external_networks' => ['networks.external', 'м', 42.5],
            'siteworks' => ['siteworks.area', 'м2', 120],
            'roads' => ['warehouse.roads', 'м2', 180],
            default => throw new \InvalidArgumentException('Unsupported package key: ' . $packageKey),
        };

        return [
            'quantity_key' => $quantityKey,
            'name' => 'Подтвержденный объем из ведомости',
            'unit' => $unit,
            'quantity' => $quantity,
            'source_refs' => [[
                'type' => 'document',
                'filename' => 'ВОР.pdf',
                'page_number' => 1,
            ]],
            'normalized_payload' => [
                'quantity_key' => $quantityKey,
                'review_required' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmedTakeoff(string $quantityKey, float $quantity, string $unit): array
    {
        return [
            'quantity_key' => $quantityKey,
            'name' => 'Подтвержденный объем по планировке',
            'unit' => $unit,
            'quantity' => $quantity,
            'source_refs' => [[
                'type' => 'drawing',
                'filename' => 'flat-plan.png',
                'page_number' => 1,
            ]],
            'normalized_payload' => [
                'quantity_key' => $quantityKey,
                'review_required' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function analysisForPackage(string $packageKey): array
    {
        $takeoffs = match ($packageKey) {
            'external_networks' => [[
                'quantity_key' => 'networks.external',
                'name' => 'Длина наружных сетей по плану',
                'unit' => 'м',
                'quantity' => 42.5,
            ]],
            'siteworks' => [[
                'quantity_key' => 'siteworks.area',
                'name' => 'Площадь благоустройства по генплану',
                'unit' => 'м2',
                'quantity' => 120,
            ]],
            'roads' => [[
                'quantity_key' => 'warehouse.roads',
                'name' => 'Площадь дорог и площадок по генплану',
                'unit' => 'м2',
                'quantity' => 180,
            ]],
            default => [],
        };

        return [
            'document_context' => [
                'facts_summary' => [
                    'total_area_m2' => 214,
                ],
                'quantity_takeoffs' => $takeoffs,
            ],
        ];
    }
}
