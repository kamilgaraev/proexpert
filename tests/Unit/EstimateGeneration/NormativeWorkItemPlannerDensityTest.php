<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeScopeRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
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

        $concreteItem = array_values(array_filter(
            $pricedItems,
            static fn (array $item): bool => ($item['quantity_formula'] ?? null) === 'foundation.concrete'
        ))[0] ?? null;
        $fallbackItems = array_values(array_filter(
            $pricedItems,
            static fn (array $item): bool => ($item['metadata']['quantity_source'] ?? null) === 'planner_fallback'
        ));

        self::assertIsArray($concreteItem);
        self::assertNotContains('quantity_review_required', $concreteItem['validation_flags']);
        self::assertSame('document_quantity', $concreteItem['metadata']['quantity_source']);
        self::assertNotEmpty($fallbackItems);

        foreach ($fallbackItems as $fallbackItem) {
            self::assertContains('document_takeoff_required', $fallbackItem['validation_flags']);
        }
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
                        'source_refs' => [['type' => 'document', 'filename' => 'electrical.pdf', 'page_number' => 2]],
                        'normalized_payload' => ['review_required' => false],
                    ],
                    [
                        'quantity_key' => 'plumbing.pipe',
                        'name' => 'Длина труб водоснабжения по ведомости',
                        'unit' => 'м',
                        'quantity' => 64,
                        'source_refs' => [['type' => 'document', 'filename' => 'plumbing.pdf', 'page_number' => 3]],
                        'normalized_payload' => ['review_required' => false],
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
                        'source_refs' => [['type' => 'drawing', 'filename' => 'ВК.pdf', 'page_number' => 1]],
                        'normalized_payload' => ['review_required' => false],
                    ],
                    [
                        'quantity_key' => 'sewerage.outlets',
                        'name' => 'Выпуски канализации по спецификации',
                        'unit' => 'шт',
                        'quantity' => 4,
                        'source_refs' => [['type' => 'drawing', 'filename' => 'ВК.pdf', 'page_number' => 2]],
                        'normalized_payload' => ['review_required' => false],
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

    public function test_separate_water_supply_package_does_not_duplicate_sewerage_pipe(): void
    {
        $localEstimate = $this->localEstimate('water_supply', 'Водоснабжение', 'engineering', 12);
        $items = $this->pricedItems($this->planner()->build(
            $localEstimate,
            $localEstimate['sections'][0],
            [
                'document_context' => [
                    'quantity_takeoffs' => [
                        [
                            'quantity_key' => 'plumbing.pipe',
                            'name' => 'Трубы водоснабжения',
                            'unit' => 'м',
                            'quantity' => 63,
                            'source_refs' => [['type' => 'drawing', 'filename' => 'ВК.pdf', 'page_number' => 1]],
                            'normalized_payload' => ['review_required' => false],
                        ],
                        [
                            'quantity_key' => 'sewerage.pipe',
                            'name' => 'Трубы канализации',
                            'unit' => 'м',
                            'quantity' => 45,
                            'source_refs' => [['type' => 'drawing', 'filename' => 'ВК.pdf', 'page_number' => 1]],
                            'normalized_payload' => ['review_required' => false],
                        ],
                    ],
                ],
            ],
        ));

        self::assertContains('Прокладка труб водоснабжения', array_column($items, 'name'));
        self::assertNotContains('Прокладка труб канализации', array_column($items, 'name'));
    }

    public function test_sewerage_accessories_are_not_invented_from_floor_area(): void
    {
        $localEstimate = $this->localEstimate('sewerage', 'Канализация', 'engineering', 12);

        $items = $this->pricedItems($this->planner()->build(
            $localEstimate,
            $localEstimate['sections'][0],
            [
                'object' => ['manual_description' => 'Помещения дома: Душ, Ванна.'],
                'document_context' => [
                    'facts_summary' => ['total_area_m2' => 180],
                    'scale_validation' => ['confirmed' => true],
                ],
            ],
        ));

        self::assertNotContains('Монтаж канализационных выпусков', array_column($items, 'name'));
        self::assertNotContains('Монтаж канализационных стояков', array_column($items, 'name'));
        self::assertNotContains('Монтаж ревизий канализации', array_column($items, 'name'));
    }

    public function test_sanitary_points_are_not_invented_from_floor_area_and_room_labels(): void
    {
        $localEstimate = $this->localEstimate('water_sewerage', 'Водоснабжение и канализация', 'plumbing', 12);

        $items = $this->pricedItems($this->planner()->build(
            $localEstimate,
            $localEstimate['sections'][0],
            [
                'document_context' => [
                    'facts_summary' => ['total_area_m2' => 180],
                    'facts' => [
                        ['label' => 'Санузел первого этажа'],
                        ['label' => 'Ванная второго этажа'],
                        ['label' => 'Душ'],
                        ['label' => 'Ванна'],
                    ],
                    'drawing_elements' => [
                        ['type' => 'room', 'label' => 'Душ'],
                        ['type' => 'room', 'label' => 'Ванна'],
                    ],
                    'quantity_takeoffs' => [[
                        'quantity_key' => 'floor_area',
                        'name' => 'Общая площадь дома по планам этажей',
                        'unit' => 'м2',
                        'quantity' => 180,
                        'source_refs' => [['type' => 'drawing', 'filename' => 'АР.pdf', 'page_number' => 1]],
                        'normalized_payload' => ['review_required' => false],
                    ]],
                ],
                'source_documents' => [[
                    'id' => 90,
                    'filename' => 'план-этажа.pdf',
                    'status' => 'ready',
                    'quality' => ['level' => 'good'],
                    'text' => 'Душ Ванна',
                    'document_understanding' => ['role_for_estimation' => 'geometry_source'],
                ]],
            ],
        ));

        $quantityKeys = array_column($items, 'quantity_formula');

        self::assertNotContains('sanitary.points', $quantityKeys);
        self::assertNotContains('plumbing.pipe', $quantityKeys);
        self::assertNotContains('sewerage.pipe', $quantityKeys);
    }

    public function test_only_explicit_fixture_takeoff_with_quantity_keeps_sanitary_points(): void
    {
        $localEstimate = $this->localEstimate('water_sewerage', 'Водоснабжение и канализация', 'plumbing', 12);
        $planner = $this->planner();

        foreach ([
            'унитаза',
            'раковиной',
            'ванны',
            'душем',
            'смесителей',
            'биде',
            'писсуара',
            'душевой поддон',
            'душевая кабина',
            'мойки',
        ] as $fixture) {
            $fixtureMentions = $this->pricedItems($planner->build(
                $localEstimate,
                $localEstimate['sections'][0],
                [
                    'document_context' => ['facts_summary' => ['total_area_m2' => 180]],
                    'source_documents' => [[
                        'id' => 91,
                        'filename' => 'спецификация-ВК.pdf',
                        'status' => 'ready',
                        'quality' => ['level' => 'good'],
                        'text' => 'Спецификацией предусмотрена установка '.$fixture.'.',
                        'document_understanding' => ['role_for_estimation' => 'quantity_source'],
                    ]],
                ],
            ));

            self::assertNotContains('sanitary.points', array_column($fixtureMentions, 'quantity_formula'), $fixture);
        }

        foreach (['Установка ванны.', 'Монтаж ванной.', 'Подключение ванны.'] as $statement) {
            $projectStatement = $this->pricedItems($planner->build(
                $localEstimate,
                $localEstimate['sections'][0],
                [
                    'object' => ['manual_description' => $statement],
                    'document_context' => ['facts_summary' => ['total_area_m2' => 180]],
                ],
            ));

            self::assertNotContains('sanitary.points', array_column($projectStatement, 'quantity_formula'), $statement);
        }

        $nestedUnderstanding = $this->pricedItems($planner->build(
            $localEstimate,
            $localEstimate['sections'][0],
            [
                'document_context' => ['facts_summary' => ['total_area_m2' => 180]],
                'source_documents' => [[
                    'id' => 92,
                    'filename' => 'спецификация-ВК-2.pdf',
                    'status' => 'ready',
                    'quality' => ['level' => 'good'],
                    'text' => 'Установка ванны — 1 шт.',
                    'facts_summary' => [
                        'document_understanding' => ['role_for_estimation' => 'quantity_source'],
                    ],
                ]],
            ],
        ));

        self::assertNotContains('sanitary.points', array_column($nestedUnderstanding, 'quantity_formula'));

        $directTakeoff = $this->pricedItems($planner->build(
            $localEstimate,
            $localEstimate['sections'][0],
            [
                'document_context' => [
                    'facts' => [['label' => 'Санузел первого этажа']],
                    'quantity_takeoffs' => [[
                        'quantity_key' => 'sanitary.points',
                        'name' => 'Сантехнические приборы по спецификации ВК',
                        'unit' => 'шт',
                        'quantity' => 5,
                        'source_refs' => [['type' => 'document', 'filename' => 'ВК.pdf', 'page_number' => 4]],
                        'normalized_payload' => ['review_required' => false],
                    ]],
                ],
            ],
        ));

        self::assertContains('sanitary.points', array_column($directTakeoff, 'quantity_formula'));
        self::assertSame(5.0, (float) array_values(array_filter(
            $directTakeoff,
            static fn (array $item): bool => ($item['quantity_formula'] ?? null) === 'sanitary.points'
        ))[0]['quantity']);
    }

    public function test_fixture_action_must_be_local_and_room_wording_is_not_a_fixture(): void
    {
        $localEstimate = $this->localEstimate('water_sewerage', 'Водоснабжение и канализация', 'plumbing', 12);

        foreach ([
            'Предусмотрена установка розеток в ванной комнате.',
            'Установка розеток. Ванная комната.',
            'Установка розеток. Ванна.',
            'Предусмотрен теплый пол в ванной.',
            'Количество розеток в ванной: 4 шт.',
            'Предусмотрен светильник для ванной.',
            'Монтаж розетки на ванной.',
        ] as $text) {
            $items = $this->pricedItems($this->planner()->build(
                $localEstimate,
                $localEstimate['sections'][0],
                [
                    'document_context' => ['facts_summary' => ['total_area_m2' => 180]],
                    'source_documents' => [[
                        'id' => 93,
                        'filename' => 'техническое-описание.pdf',
                        'status' => 'ready',
                        'quality' => ['level' => 'good'],
                        'text' => $text,
                        'document_understanding' => ['role_for_estimation' => 'quantity_source'],
                    ]],
                ],
            ));

            self::assertNotContains('sanitary.points', array_column($items, 'quantity_formula'), $text);
        }
    }

    public function test_direct_sanitary_takeoff_is_detected_outside_package_local_definitions(): void
    {
        $localEstimate = $this->localEstimate('custom-plumbing', 'Специальные работы ВК', 'plumbing', 12);

        $items = $this->pricedItems($this->planner()->build(
            $localEstimate,
            $localEstimate['sections'][0],
            [
                'document_context' => [
                    'quantity_takeoffs' => [[
                        'quantity_key' => 'sanitary.points',
                        'name' => 'Приборы по спецификации ВК',
                        'unit' => 'шт',
                        'quantity' => 6,
                        'source_refs' => [['type' => 'document', 'filename' => 'ВК.pdf', 'page_number' => 4]],
                        'normalized_payload' => ['review_required' => false],
                    ]],
                    'scope_inferences' => [[
                        'inference_type' => 'specification_takeoff',
                        'scope_type' => 'plumbing',
                        'title' => 'Монтаж сантехнических приборов',
                        'confidence' => 0.91,
                        'source_refs' => [['type' => 'document', 'filename' => 'ВК.pdf', 'page_number' => 4]],
                        'review_required' => false,
                        'normalized_payload' => [
                            'quantity_key' => 'sanitary.points',
                            'quantity_value' => 6,
                            'unit' => 'шт',
                        ],
                    ]],
                ],
            ],
        ));

        $sanitaryItem = array_values(array_filter(
            $items,
            static fn (array $item): bool => ($item['quantity_formula'] ?? null) === 'sanitary.points'
        ))[0] ?? null;

        self::assertIsArray($sanitaryItem);
        self::assertSame(6.0, (float) $sanitaryItem['quantity']);
    }

    public function test_singular_source_ref_supports_provenance_aware_fixture_inference(): void
    {
        $localEstimate = $this->localEstimate('custom-plumbing', 'Специальные работы ВК', 'plumbing', 12);

        $items = $this->pricedItems($this->planner()->build(
            $localEstimate,
            $localEstimate['sections'][0],
            [
                'document_context' => [
                    'scope_inferences' => [[
                        'inference_type' => 'specification_takeoff',
                        'scope_type' => 'plumbing',
                        'title' => 'Установка биде',
                        'confidence' => 0.91,
                        'source_ref' => ['type' => 'document', 'filename' => 'ВК.pdf', 'page_number' => 4],
                        'review_required' => false,
                        'normalized_payload' => [
                            'quantity_key' => 'sanitary.points',
                            'quantity_value' => 2,
                            'unit' => 'шт',
                        ],
                    ]],
                ],
            ],
        ));

        $sanitaryItem = array_values(array_filter(
            $items,
            static fn (array $item): bool => ($item['quantity_formula'] ?? null) === 'sanitary.points'
        ))[0] ?? null;

        self::assertIsArray($sanitaryItem);
        self::assertSame(2.0, (float) $sanitaryItem['quantity']);
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
                'context_text' => 'House ventilation and fire safety are shown without a quantity takeoff.',
                'facts_summary' => [
                    'total_area_m2' => 214,
                ],
                'quantity_takeoffs' => [[
                    'quantity_key' => 'floor_area',
                    'name' => 'Confirmed total floor area',
                    'unit' => 'm2',
                    'quantity' => 214,
                    'source_refs' => [['type' => 'drawing', 'filename' => 'plan.pdf', 'page_number' => 1]],
                    'normalized_payload' => ['review_required' => false],
                ]],
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

    public function test_house_ventilation_does_not_include_office_or_warehouse_air_distributors(): void
    {
        $localEstimate = $this->localEstimate('ventilation', 'Вентиляция', 'ventilation', 12);
        $items = $this->planner()->build(
            $localEstimate,
            $localEstimate['sections'][0],
            [
                'object' => [
                    'object_type' => 'house_with_garage',
                    'building_type' => 'custom',
                    'description' => 'Индивидуальный жилой дом площадью 180 м2. Вентиляция. В служебном шаблоне упомянуты офис и склад.',
                ],
                'document_context' => [
                    'context_text' => 'Вентиляция дома. В служебном шаблоне упомянуты офис и склад.',
                    'scope_inferences' => [[
                        'inference_type' => 'specification_takeoff',
                        'scope_type' => 'ventilation',
                        'title' => 'Воздухораспределители офиса',
                        'confidence' => 0.91,
                        'source_refs' => [[
                            'type' => 'drawing',
                            'filename' => 'ОВ.pdf',
                            'page_number' => 2,
                        ]],
                        'normalized_payload' => [
                            'quantity_key' => 'ventilation.office_points',
                            'quantity_value' => 8,
                            'unit' => 'шт',
                        ],
                    ]],
                    'quantity_takeoffs' => [[
                        'quantity_key' => 'ventilation.air_exchange',
                        'name' => 'Приточно-вытяжная вентиляция',
                        'unit' => 'м',
                        'quantity' => 54,
                        'source_refs' => [[
                            'type' => 'drawing',
                            'filename' => 'ОВ.pdf',
                            'page_number' => 1,
                        ]],
                        'normalized_payload' => [
                            'quantity_key' => 'ventilation.air_exchange',
                            'review_required' => false,
                        ],
                    ]],
                ],
            ]
        );
        $names = array_column($items, 'name');

        self::assertContains('Приточно-вытяжная вентиляция', $names);
        self::assertNotContains('Воздухораспределители офиса', $names);
        self::assertNotContains('Воздухораспределители склада', $names);
    }

    public function test_house_plan_excludes_office_and_warehouse_only_work_definitions(): void
    {
        $analysis = [
            'object' => [
                'object_type' => 'house_with_garage',
                'building_type' => 'custom',
                'description' => 'Индивидуальный жилой дом площадью 180 м2.',
            ],
            'document_context' => [
                'context_text' => 'В служебном шаблоне упомянуты офис, склад и промышленный пол.',
                'facts_summary' => [
                    'total_area_m2' => 180,
                ],
            ],
        ];
        $planner = $this->planner();
        $names = [];
        $slabItems = [];

        foreach ([
            'walls' => 'walls',
            'slabs' => 'slabs',
            'openings' => 'openings',
            'heating' => 'heating',
        ] as $packageKey => $scopeType) {
            $localEstimate = $this->localEstimate($packageKey, $packageKey, $scopeType, 12);
            $items = $planner->build($localEstimate, $localEstimate['sections'][0], $analysis);
            $names = [
                ...$names,
                ...array_column($items, 'name'),
            ];
            if ($packageKey === 'slabs') {
                $slabItems = $items;
            }
        }

        self::assertContains('Устройство внутренних перегородок', $names);
        self::assertContains('Бетонирование монолитного перекрытия', $names);
        self::assertContains('Армирование монолитного перекрытия', $names);
        $slabRebar = array_values(array_filter(
            $slabItems,
            static fn (array $item): bool => ($item['quantity_formula'] ?? null) === 'slabs.rebar',
        ))[0] ?? null;
        self::assertIsArray($slabRebar);
        self::assertSame('06-23-003-05', $slabRebar['specialization_scenario']['normative_rate_code'] ?? null);
        self::assertSame('monolithic_floor_reinforcement_steel', $slabRebar['specialization_scenario']['assumption_code'] ?? null);
        self::assertNotContains('Монтаж оконных блоков', $names);
        self::assertNotContains('Монтаж дверных блоков', $names);
        self::assertNotContains('Устройство плиты пола', $names);
        self::assertNotContains('Офисные перегородки', $names);
        self::assertNotContains('Топпинг промышленного пола', $names);
        self::assertNotContains('Деформационные швы пола', $names);
        self::assertNotContains('Монтаж ворот', $names);
        self::assertNotContains('Погрузочные узлы', $names);
        self::assertNotContains('Воздушно-тепловые завесы', $names);
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
                        'unit' => 'шт',
                        'quantity' => 2,
                        'source_refs' => [['type' => 'drawing', 'filename' => 'АР.pdf', 'page_number' => 4]],
                        'normalized_payload' => ['review_required' => false],
                    ],
                    [
                        'quantity_key' => 'stairs.railings',
                        'name' => 'Ограждение лестниц по спецификации',
                        'unit' => 'м',
                        'quantity' => 22,
                        'source_refs' => [['type' => 'drawing', 'filename' => 'АР.pdf', 'page_number' => 4]],
                        'normalized_payload' => ['review_required' => false],
                    ],
                ],
            ],
        ]);

        self::assertSame([], $items);
    }

    public function test_custom_scope_ignores_regular_scope_inferences(): void
    {
        $localEstimate = $this->localEstimate('local-custom', 'Custom package', 'custom', 12);

        $items = $this->planner()->build($localEstimate, $localEstimate['sections'][0], [
            'document_context' => [
                'scope_inferences' => [[
                    'inference_type' => 'specification_takeoff',
                    'scope_type' => 'earthworks',
                    'title' => 'Backfill from statement',
                    'source_refs' => [[
                        'type' => 'document',
                        'filename' => 'statement.pdf',
                        'page_number' => 1,
                    ]],
                    'normalized_payload' => [
                        'quantity_key' => 'earth.backfill',
                        'quantity_value' => 42.0,
                        'unit' => 'm3',
                    ],
                ]],
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
                        'unit' => 'шт',
                        'quantity' => 2,
                        'source_refs' => [['type' => 'drawing', 'filename' => 'АР.pdf', 'page_number' => 4]],
                        'normalized_payload' => ['review_required' => false],
                    ],
                    [
                        'quantity_key' => 'stairs.railings',
                        'name' => 'Ограждение лестниц по спецификации',
                        'unit' => 'м',
                        'quantity' => 22,
                        'source_refs' => [['type' => 'drawing', 'filename' => 'АР.pdf', 'page_number' => 4]],
                        'normalized_payload' => ['review_required' => false],
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

    public function test_known_planner_package_keys_expose_domain_specific_takeoff_required_items(): void
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

            foreach ($pricedItems as $pricedItem) {
                if (($pricedItem['metadata']['quantity_source'] ?? null) !== 'planner_fallback') {
                    continue;
                }

                self::assertContains('document_takeoff_required', $pricedItem['validation_flags'], $packageKey);
            }
        }
    }

    public function test_known_planner_package_keys_keep_confirmed_takeoffs_and_mark_missing_quantities(): void
    {
        $planner = $this->planner();

        foreach ($this->knownPackageScopes() as $packageKey => $scopeType) {
            $localEstimate = $this->localEstimate($packageKey, $packageKey, $scopeType, 4);
            $items = $planner->build($localEstimate, $localEstimate['sections'][0], [
                'object' => ['object_type' => 'mixed_warehouse_office'],
                'document_context' => [
                    'quantity_takeoffs' => [
                        $this->confirmedTakeoffForPackage($packageKey),
                    ],
                ],
            ]);
            $pricedItems = $this->pricedItems($items);
            $sourceBackedItems = array_values(array_filter(
                $pricedItems,
                static fn (array $item): bool => ($item['metadata']['quantity_source'] ?? null) !== 'planner_fallback'
            ));

            self::assertNotEmpty($pricedItems, $packageKey);
            self::assertNotEmpty($sourceBackedItems, $packageKey);

            foreach ($pricedItems as $pricedItem) {
                if (($pricedItem['metadata']['quantity_source'] ?? null) === 'planner_fallback') {
                    self::assertContains('document_takeoff_required', $pricedItem['validation_flags'], $packageKey);

                    continue;
                }

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
                'object' => ['object_type' => 'mixed_warehouse_office'],
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
                    $packageKey.':'.($item['quantity_formula'] ?? $item['key'])
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
            'object' => ['object_type' => 'mixed_warehouse_office'],
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
                        'source_refs' => [
                            [
                                'type' => 'drawing',
                                'filename' => 'АР-1.pdf',
                                'page_number' => 5,
                            ],
                        ],
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

        $floorConcrete = array_values(array_filter(
            $industrialFloor,
            static fn (array $item): bool => ($item['quantity_formula'] ?? null) === 'warehouse.floor_concrete'
        ))[0] ?? null;
        $flatRoofItems = array_values(array_filter(
            $roof,
            static fn (array $item): bool => ($item['quantity_formula'] ?? null) === 'roof.flat_area'
        ));

        self::assertIsArray($floorConcrete);
        self::assertCount(4, $flatRoofItems);
        self::assertSame(75.6, (float) $floorConcrete['quantity']);
        self::assertContains('roof.flat_area', array_column($roof, 'quantity_formula'));
        self::assertNotContains('roof.area', array_column($roof, 'quantity_formula'));

        foreach ($industrialFloor as $item) {
            if (($item['metadata']['quantity_source'] ?? null) === 'planner_fallback') {
                self::assertContains('document_takeoff_required', $item['validation_flags']);
            }
        }

        foreach ($roof as $item) {
            if (($item['metadata']['quantity_source'] ?? null) === 'planner_fallback') {
                self::assertContains('document_takeoff_required', $item['validation_flags']);
            }
        }
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

        $reviewItem = array_values(array_filter(
            $items,
            static fn (array $item): bool => ($item['item_type'] ?? null) === 'quantity_review'
        ))[0] ?? null;
        $fallbackItems = array_values(array_filter(
            $items,
            static fn (array $item): bool => ($item['metadata']['quantity_source'] ?? null) === 'planner_fallback'
        ));

        self::assertIsArray($reviewItem);
        self::assertSame('rough.walls', $reviewItem['quantity_formula']);
        self::assertSame(220.5, (float) $reviewItem['quantity']);
        self::assertSame('not_applicable', $reviewItem['pricing_status']);
        self::assertSame('quantity_review_required', $reviewItem['pricing_blocker']);
        self::assertContains('quantity_review_required', $reviewItem['validation_flags']);
        self::assertSame('rough.walls', $reviewItem['metadata']['quantity_key'] ?? null);

        foreach ($fallbackItems as $fallbackItem) {
            self::assertContains('document_takeoff_required', $fallbackItem['validation_flags']);
        }
    }

    public function test_quantity_learning_conflict_keeps_current_takeoff_quantity_and_requires_review(): void
    {
        $localEstimate = $this->localEstimate('rough_finishing', 'Черновая отделка', 'finishing', 6);
        $items = $this->planner()->build(
            $localEstimate,
            $localEstimate['sections'][0],
            [
                'document_context' => [
                    'quantity_takeoffs' => [[
                        'scope_key' => 'wall_finish_area',
                        'name' => 'Площадь стен по новой планировке',
                        'unit' => 'm2',
                        'quantity' => 320.0,
                        'confidence' => 0.82,
                        'source_refs' => [[
                            'type' => 'drawing',
                            'document_id' => 13,
                            'filename' => 'flat-plan.png',
                            'page_number' => 1,
                        ]],
                    ]],
                    'quantity_learning_hints' => [
                        'rough.walls' => [
                            'quantity_key' => 'rough.walls',
                            'learning_example_id' => 501,
                            'quantity' => 218.25,
                            'unit' => 'm2',
                            'quantity_basis' => 'Подтверждено сметчиком по прошлой планировке.',
                            'source_quality_score' => 1.0,
                            'confidence' => 1.0,
                            'same_project' => true,
                            'examples_count' => 1,
                        ],
                    ],
                ],
            ]
        );

        $reviewItem = array_values(array_filter(
            $items,
            static fn (array $item): bool => ($item['quantity_formula'] ?? null) === 'rough.walls'
        ))[0] ?? null;

        self::assertIsArray($reviewItem);
        self::assertSame('quantity_review', $reviewItem['item_type']);
        self::assertSame(320.0, (float) $reviewItem['quantity']);
        self::assertSame('document_quantity_learning_conflict', $reviewItem['metadata']['quantity_source'] ?? null);
        self::assertSame(218.25, (float) $reviewItem['metadata']['quantity_learning_hint']['quantity']);
        self::assertSame(501, $reviewItem['metadata']['quantity_learning_hint']['learning_example_id']);
        self::assertContains('quantity_review_required', $reviewItem['validation_flags']);
        self::assertStringContainsString('требуется проверка', $reviewItem['quantity_basis']);
    }

    public function test_summary_area_without_source_refs_does_not_invent_a_rough_floor_construction(): void
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

        $reviewItem = array_values(array_filter(
            $items,
            static fn (array $item): bool => ($item['quantity_formula'] ?? null) === 'rough.floor'
        ))[0] ?? null;

        self::assertNull($reviewItem);

        foreach ($items as $item) {
            if (($item['metadata']['quantity_source'] ?? null) === 'planner_fallback') {
                self::assertContains('document_takeoff_required', $item['validation_flags']);
            }
        }
    }

    public function test_underspecified_works_require_direct_confirmed_takeoffs(): void
    {
        $planner = $this->planner();
        $cases = [
            'earthworks' => ['foundation', ['earth.export']],
            'stairs' => ['stairs', ['stairs.flights', 'stairs.landings']],
            'roof' => ['roof', ['roof.rafters']],
            'electrical' => ['electrical', ['electrical.grounding']],
            'rough_finishing' => ['finishing', ['rough.floor']],
        ];

        foreach ($cases as $packageKey => [$scopeType, $quantityKeys]) {
            $localEstimate = $this->localEstimate($packageKey, $packageKey, $scopeType, 4);
            $withoutTakeoff = $planner->build($localEstimate, $localEstimate['sections'][0], [
                'document_context' => ['facts_summary' => ['total_area_m2' => 180]],
            ]);
            $withoutTakeoffFormulas = array_column($withoutTakeoff, 'quantity_formula');

            foreach ($quantityKeys as $quantityKey) {
                self::assertNotContains($quantityKey, $withoutTakeoffFormulas, $quantityKey);
            }

            $withTakeoff = $planner->build($localEstimate, $localEstimate['sections'][0], [
                'document_context' => [
                    'quantity_takeoffs' => array_map(
                        fn (string $quantityKey): array => $this->confirmedTakeoff($quantityKey, 12.5, 'm'),
                        $quantityKeys,
                    ),
                ],
            ]);
            $withTakeoffFormulas = array_column($withTakeoff, 'quantity_formula');

            foreach ($quantityKeys as $quantityKey) {
                self::assertContains($quantityKey, $withTakeoffFormulas, $quantityKey);
            }
        }
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

        $reviewItem = array_values(array_filter(
            $items,
            static fn (array $item): bool => ($item['quantity_formula'] ?? null) === 'finish.baseboard'
        ))[0] ?? null;

        self::assertIsArray($reviewItem);
        self::assertSame('quantity_review', $reviewItem['item_type']);
        self::assertSame(77.0, (float) $reviewItem['quantity']);
        self::assertSame('м', $reviewItem['unit']);
        self::assertSame('quantity_review_required', $reviewItem['pricing_blocker']);
        self::assertContains('quantity_review_required', $reviewItem['validation_flags']);
        self::assertSame('finish.baseboard', $reviewItem['metadata']['quantity_key'] ?? null);

        foreach ($items as $item) {
            if (($item['metadata']['quantity_source'] ?? null) === 'planner_fallback') {
                self::assertContains('document_takeoff_required', $item['validation_flags']);
            }
        }
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
                    [
                        'scope_key' => 'wet_zone_waterproofing_area',
                        'name' => 'Wet zone waterproofing area from floor plan',
                        'unit' => 'м2',
                        'quantity' => 41.6,
                        'confidence' => 0.67,
                        'source_refs' => [[
                            'type' => 'drawing',
                            'filename' => 'flat-plan.png',
                            'page_number' => 1,
                        ]],
                        'normalized_payload' => [
                            'quantity_key' => 'sanitary.waterproofing',
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
        $waterproofingItem = array_values(array_filter(
            $plumbingItems,
            static fn (array $item): bool => ($item['quantity_formula'] ?? null) === 'sanitary.waterproofing'
        ))[0] ?? null;

        self::assertIsArray($paintItem);
        self::assertSame(312.4, (float) $paintItem['quantity']);
        self::assertNotContains('quantity_review_required', $paintItem['validation_flags']);
        self::assertIsArray($tileItem);
        self::assertSame(54.2, (float) $tileItem['quantity']);
        self::assertNotContains('quantity_review_required', $tileItem['validation_flags']);
        self::assertNotSame('компл', $tileItem['unit']);
        self::assertSame('Облицовка плиткой мокрых зон', $tileItem['name']);
        self::assertIsArray($waterproofingItem);
        self::assertSame(41.6, (float) $waterproofingItem['quantity']);
        self::assertNotContains('quantity_review_required', $waterproofingItem['validation_flags']);
        self::assertSame('Гидроизоляция мокрых зон', $waterproofingItem['name']);
        self::assertNotSame($tileItem['normative_search_key'], $waterproofingItem['normative_search_key']);

        $classifier = new WorkIntentClassifier(new NormativeScopeRuleCatalog);
        self::assertSame('tiling', $classifier->classify($tileItem)->action);
        self::assertSame('waterproofing', $classifier->classify($waterproofingItem)->action);
    }

    public function test_site_preparation_does_not_duplicate_earthworks_base_planning(): void
    {
        $analysis = [
            'document_context' => [
                'quantity_takeoffs' => [
                    $this->confirmedTakeoff('earth.plan', 192.8, 'м2'),
                ],
            ],
        ];
        $preconstruction = $this->localEstimate('preconstruction', 'Подготовительные работы', 'site', 3);
        $earthworks = $this->localEstimate('earthworks', 'Земляные работы', 'foundation', 4);
        $planner = $this->planner();

        $preconstructionFormulas = array_column(
            $planner->build($preconstruction, $preconstruction['sections'][0], $analysis),
            'quantity_formula'
        );
        $earthworkFormulas = array_column(
            $planner->build($earthworks, $earthworks['sections'][0], $analysis),
            'quantity_formula'
        );

        self::assertNotContains('earth.plan', $preconstructionFormulas);
        self::assertSame(1, count(array_keys($earthworkFormulas, 'earth.plan', true)));
    }

    public function test_wet_zone_operations_require_their_own_document_takeoff(): void
    {
        $localEstimate = $this->localEstimate('plumbing', 'Водоснабжение', 'plumbing', 4);

        $formulas = array_column(
            $this->planner()->build($localEstimate, $localEstimate['sections'][0], []),
            'quantity_formula'
        );

        self::assertNotContains('sanitary.tile', $formulas);
        self::assertNotContains('sanitary.waterproofing', $formulas);

        $analysis = [
            'document_context' => [
                'quantity_takeoffs' => [
                    $this->confirmedTakeoff('sanitary.tile', 21.4, 'м2'),
                ],
            ],
        ];
        $formulas = array_column(
            $this->planner()->build($localEstimate, $localEstimate['sections'][0], $analysis),
            'quantity_formula'
        );

        self::assertContains('sanitary.tile', $formulas);
        self::assertNotContains('sanitary.waterproofing', $formulas);
    }

    public function test_floor_plan_rough_and_finish_packages_do_not_duplicate_finish_intents(): void
    {
        $analysis = [
            'object' => ['object_type' => 'house'],
            'document_context' => [
                'quantity_takeoffs' => [
                    $this->confirmedTakeoff('rough.floor', 87.14, 'м2'),
                    $this->confirmedTakeoff('rough.walls', 235.28, 'м2'),
                    $this->confirmedTakeoff('rough.ceiling', 87.14, 'м2'),
                    $this->confirmedTakeoff('finish.floor', 87.14, 'м2'),
                    $this->confirmedTakeoff('finish.paint', 235.28, 'м2'),
                    $this->confirmedTakeoff('finish.ceiling', 87.14, 'м2'),
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
        $sourceBackedFinishFormulas = array_column(array_values(array_filter(
            $finishItems,
            static fn (array $item): bool => ($item['metadata']['quantity_source'] ?? null) !== 'planner_fallback'
        )), 'quantity_formula');
        $fallbackFinishItems = array_values(array_filter(
            $finishItems,
            static fn (array $item): bool => ($item['metadata']['quantity_source'] ?? null) === 'planner_fallback'
        ));

        self::assertSame(['rough.floor', 'rough.walls', 'rough.ceiling'], $roughFormulas);
        self::assertSame(['finish.floor', 'finish.paint', 'finish.ceiling'], $sourceBackedFinishFormulas);
        self::assertContains('finish.baseboard', $finishFormulas);
        self::assertSame([], array_values(array_intersect($roughFormulas, $finishFormulas)));

        foreach ($fallbackFinishItems as $fallbackItem) {
            self::assertContains('document_takeoff_required', $fallbackItem['validation_flags']);
        }
    }

    public function test_residential_preliminary_items_receive_catalog_material_scenario_and_warning(): void
    {
        $localEstimate = $this->localEstimate('finish_finishing', 'Чистовая отделка', 'finishing', 6);
        $analysis = [
            'object' => ['object_type' => 'house'],
            'document_context' => [
                'quantity_takeoffs' => [
                    $this->confirmedTakeoff('finish.floor', 87.14, 'м2'),
                ],
            ],
        ];

        $items = $this->pricedItems($this->planner()->build(
            $localEstimate,
            $localEstimate['sections'][0],
            $analysis,
        ));
        $floor = array_values(array_filter(
            $items,
            static fn (array $item): bool => ($item['quantity_formula'] ?? null) === 'finish.floor',
        ))[0] ?? null;

        self::assertIsArray($floor);
        self::assertSame('residential_preliminary_common:v12', $floor['specialization_scenario']['scenario_id'] ?? null);
        self::assertSame(['ламинат', 'ламинированн'], $floor['specialization_scenario']['material_markers'] ?? null);
        self::assertSame('warning', $floor['metadata']['material_assumption']['severity'] ?? null);
        self::assertTrue($floor['metadata']['material_assumption']['requires_confirmation'] ?? false);
        self::assertSame(
            'Предварительно принято чистовое покрытие пола из ламината. Материал нужно уточнить по ведомости отделки.',
            $floor['metadata']['material_assumption']['message'] ?? null,
        );
        self::assertContains('preliminary_material_assumption', $floor['validation_flags']);
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

    public function test_preconstruction_does_not_invent_temporary_fence_from_building_area(): void
    {
        $localEstimate = $this->localEstimate('preconstruction', 'Preconstruction', 'site', 2);

        $items = $this->planner()->build($localEstimate, $localEstimate['sections'][0], [
            'document_context' => [
                'facts_summary' => ['total_area_m2' => 180],
            ],
        ]);

        self::assertNotContains('site.fence', array_column($items, 'quantity_formula'));
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
                'key' => $key.'-section',
                'title' => $title,
                'construction_part' => $scopeType,
                'source_refs' => [],
            ]],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function pricedItems(array $items): array
    {
        return array_values(array_filter($items, static fn (array $item): bool => ($item['item_type'] ?? null) === 'priced_work'));
    }

    private function planner(): NormativeWorkItemPlannerService
    {
        return new NormativeWorkItemPlannerService(
            new ProjectDocumentNormativeReferenceExtractor,
            new EstimatorScopeInferenceService,
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
            'slabs' => ['slabs.concrete', 'м3', 75.6],
            'industrial_floor' => ['warehouse.floor_concrete', 'м3', 75.6],
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
            default => throw new \InvalidArgumentException('Unsupported package key: '.$packageKey),
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
