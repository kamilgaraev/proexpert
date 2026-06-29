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
        $localEstimate = $this->localEstimate('preconstruction', 'Подготовительные работы', 'site', 12);
        $items = $this->planner()->build($localEstimate, $localEstimate['sections'][0], [
            'document_context' => [
                'facts_summary' => [
                    'total_area_m2' => 214,
                ],
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

        self::assertContains('quantity_review_required', $pricedItems[0]['validation_flags']);
    }

    public function test_package_key_selects_specific_normative_intents_even_when_scope_is_broad(): void
    {
        $analysis = [
            'document_context' => [
                'facts_summary' => [
                    'total_area_m2' => 214,
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

    public function test_sewerage_package_uses_specific_normative_intents_instead_of_generic_complex_work(): void
    {
        $localEstimate = $this->localEstimate('sewerage', 'Канализация', 'engineering', 12);

        $pricedItems = $this->pricedItems($this->planner()->build($localEstimate, $localEstimate['sections'][0], [
            'document_context' => [
                'facts_summary' => [
                    'total_area_m2' => 214,
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

    public function test_unknown_custom_scope_does_not_create_generic_complex_work(): void
    {
        $localEstimate = $this->localEstimate('local-custom', 'Основные строительные работы', 'custom', 12);

        $items = $this->planner()->build($localEstimate, $localEstimate['sections'][0], [
            'document_context' => [
                'facts_summary' => [
                    'total_area_m2' => 214,
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
                'facts_summary' => [
                    'total_area_m2' => 214,
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

    public function test_known_planner_package_keys_generate_subject_items_without_custom_fallback(): void
    {
        $planner = $this->planner();

        foreach ($this->knownPackageScopes() as $packageKey => $scopeType) {
            $localEstimate = $this->localEstimate($packageKey, $packageKey, $scopeType, 4);
            $items = $planner->build($localEstimate, $localEstimate['sections'][0], $this->analysisForPackage($packageKey));
            $pricedItems = $this->pricedItems($items);
            $names = array_column($pricedItems, 'name');

            self::assertNotEmpty($pricedItems, $packageKey);
            self::assertSame(count($pricedItems), count($items), $packageKey);
            self::assertNotContains('operation', array_column($items, 'item_type'), $packageKey);
            self::assertNotContains('custom', array_column($pricedItems, 'work_category'), $packageKey);
            self::assertNotContains('Комплекс строительных работ', $names, $packageKey);
        }
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

        self::assertGreaterThanOrEqual(4, count($industrialFloor));
        self::assertGreaterThanOrEqual(5, count($roof));
        self::assertSame('warehouse.floor_concrete', $industrialFloor[0]['quantity_formula']);
        self::assertSame(75.6, (float) $industrialFloor[0]['quantity']);
        self::assertContains('roof.flat_area', array_column($roof, 'quantity_formula'));
        self::assertNotContains('roof.area', array_column($roof, 'quantity_formula'));
    }

    public function test_drawing_takeoff_review_flag_is_preserved_for_normative_item_quantity(): void
    {
        $localEstimate = $this->localEstimate('rough_finishing', 'Черновая отделка', 'finishing', 6);
        $items = $this->pricedItems($this->planner()->build(
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
        ));
        $wallItem = array_values(array_filter(
            $items,
            static fn (array $item): bool => ($item['quantity_formula'] ?? null) === 'rough.walls'
        ))[0] ?? null;

        self::assertIsArray($wallItem);
        self::assertSame(220.5, (float) $wallItem['quantity']);
        self::assertContains('quantity_review_required', $wallItem['validation_flags']);
    }

    public function test_floor_plan_wall_and_wet_zone_takeoffs_feed_matching_normative_intents(): void
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
                            'review_required' => true,
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
                            'review_required' => true,
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
        self::assertContains('quantity_review_required', $paintItem['validation_flags']);
        self::assertIsArray($tileItem);
        self::assertSame(54.2, (float) $tileItem['quantity']);
        self::assertContains('quantity_review_required', $tileItem['validation_flags']);
        self::assertNotSame('компл', $tileItem['unit']);
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
