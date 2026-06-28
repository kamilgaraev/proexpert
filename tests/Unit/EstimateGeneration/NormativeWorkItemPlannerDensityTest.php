<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;
use PHPUnit\Framework\TestCase;

class NormativeWorkItemPlannerDensityTest extends TestCase
{
    public function test_planner_creates_normative_priced_items_and_composition_rows_from_project_scope(): void
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

        self::assertGreaterThanOrEqual(12, count($items));
        self::assertNotEmpty($pricedItems);
        self::assertContains('operation', array_column($items, 'item_type'));
        self::assertSame(count($pricedItems), count(array_unique(array_column($pricedItems, 'normative_search_key'))));

        foreach ($pricedItems as $item) {
            self::assertSame([], $item['materials']);
            self::assertSame([], $item['labor']);
            self::assertSame([], $item['machinery']);
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
}
