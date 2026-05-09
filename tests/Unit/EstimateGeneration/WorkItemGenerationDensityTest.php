<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\WorkItemGenerationService;
use Tests\TestCase;

class WorkItemGenerationDensityTest extends TestCase
{
    public function test_package_generation_creates_real_detail_without_fractional_fixed_work(): void
    {
        $items = app(WorkItemGenerationService::class)->build([
            'key' => 'preconstruction',
            'title' => 'Подготовительные работы',
            'scope_type' => 'site',
            'source_refs' => [],
            'target_items_min' => 12,
        ], [
            'object' => [
                'area' => 214,
                'floors' => 2,
                'rooms' => 7,
            ],
        ]);

        $this->assertGreaterThanOrEqual(12, count($items));
        $this->assertSame(count($items), count(array_unique(array_column($items, 'normative_search_key'))));

        foreach ($items as $item) {
            $this->assertGreaterThanOrEqual(
                1.0,
                (float) $item['quantity'],
                'Подготовительные платные позиции не должны превращаться в дробные комплекты.'
            );
        }
    }

    public function test_package_key_selects_specific_templates_even_when_scope_is_broad(): void
    {
        $analysis = [
            'object' => [
                'area' => 214,
                'floors' => 2,
                'rooms' => 7,
            ],
        ];

        $stairs = app(WorkItemGenerationService::class)->build([
            'key' => 'stairs',
            'title' => 'Лестницы',
            'scope_type' => 'slabs',
            'source_refs' => [],
            'target_items_min' => 8,
        ], $analysis);

        $electrical = app(WorkItemGenerationService::class)->build([
            'key' => 'electrical',
            'title' => 'Электрика',
            'scope_type' => 'engineering',
            'source_refs' => [],
            'target_items_min' => 12,
        ], $analysis);

        $plumbing = app(WorkItemGenerationService::class)->build([
            'key' => 'plumbing',
            'title' => 'Водоснабжение',
            'scope_type' => 'engineering',
            'source_refs' => [],
            'target_items_min' => 12,
        ], $analysis);

        $this->assertContains('stairs', array_column($stairs, 'work_category'));
        $this->assertNotSame(
            array_column($electrical, 'normative_search_key'),
            array_column($plumbing, 'normative_search_key')
        );
    }

    public function test_warehouse_packages_do_not_fall_back_to_custom_template(): void
    {
        $analysis = [
            'object' => [
                'building_type' => 'Склад',
                'area' => 2000,
                'floors' => 1,
            ],
        ];

        $industrialFloor = app(WorkItemGenerationService::class)->build([
            'key' => 'industrial_floor',
            'title' => 'Промышленный пол',
            'scope_type' => 'slabs',
            'source_refs' => [],
            'target_items_min' => 12,
        ], $analysis);

        $metalFrame = app(WorkItemGenerationService::class)->build([
            'key' => 'metal_frame',
            'title' => 'Металлокаркас',
            'scope_type' => 'structural',
            'source_refs' => [],
            'target_items_min' => 12,
        ], $analysis);

        $this->assertContains('industrial_floor', array_column($industrialFloor, 'work_category'));
        $this->assertContains('metal_frame', array_column($metalFrame, 'work_category'));
    }

    public function test_mixed_office_warehouse_generates_dense_priced_items_and_flat_roof(): void
    {
        $analysis = [
            'object' => [
                'building_type' => 'Производственное',
                'description' => 'Офисно-складской корпус 780 м2. На первом этаже склад 420 м2 с промышленным бетонным полом, воротами и пожарной сигнализацией. На втором этаже офисы 260 м2, санузлы, серверная и переговорная. Плоская кровля.',
                'area' => 780,
            ],
        ];

        $industrialFloor = app(WorkItemGenerationService::class)->build([
            'key' => 'industrial_floor',
            'title' => 'Промышленный пол',
            'scope_type' => 'slabs',
            'source_refs' => [],
            'target_items_min' => 20,
        ], $analysis);

        $roof = app(WorkItemGenerationService::class)->build([
            'key' => 'roof',
            'title' => 'Кровля',
            'scope_type' => 'roof',
            'source_refs' => [],
            'target_items_min' => 20,
        ], $analysis);

        $officeFinishing = app(WorkItemGenerationService::class)->build([
            'key' => 'office_finishing',
            'title' => 'Офисная отделка',
            'scope_type' => 'finishing',
            'source_refs' => [],
            'target_items_min' => 20,
        ], $analysis);
        $heating = app(WorkItemGenerationService::class)->build([
            'key' => 'heating',
            'title' => 'Отопление',
            'scope_type' => 'heating',
            'source_refs' => [],
            'target_items_min' => 20,
        ], $analysis);
        $ventilation = app(WorkItemGenerationService::class)->build([
            'key' => 'ventilation',
            'title' => 'Вентиляция',
            'scope_type' => 'ventilation',
            'source_refs' => [],
            'target_items_min' => 20,
        ], $analysis);

        $pricedIndustrialFloor = array_values(array_filter($industrialFloor, fn (array $item): bool => ($item['item_type'] ?? null) === 'priced_work'));
        $pricedRoofNames = array_column(array_filter($roof, fn (array $item): bool => ($item['item_type'] ?? null) === 'priced_work'), 'name');
        $pricedOfficeFinishing = array_values(array_filter($officeFinishing, fn (array $item): bool => ($item['item_type'] ?? null) === 'priced_work'));
        $pricedHeating = array_values(array_filter($heating, fn (array $item): bool => ($item['item_type'] ?? null) === 'priced_work'));
        $pricedVentilation = array_values(array_filter($ventilation, fn (array $item): bool => ($item['item_type'] ?? null) === 'priced_work'));

        $this->assertGreaterThanOrEqual(6, count($pricedIndustrialFloor));
        $this->assertGreaterThanOrEqual(6, count($pricedOfficeFinishing));
        $this->assertGreaterThanOrEqual(4, count($pricedHeating));
        $this->assertGreaterThanOrEqual(5, count($pricedVentilation));
        $this->assertSame(420.0, (float) $pricedIndustrialFloor[0]['quantity']);
        $this->assertNotContains('site.setup', array_column($pricedHeating, 'quantity_formula'));
        $this->assertContains('Устройство плоской кровли по профнастилу', $pricedRoofNames);
        $this->assertNotContains('Монтаж металлочерепицы', $pricedRoofNames);
        $this->assertNotContains('Монтаж стропильной системы', $pricedRoofNames);
    }
}
