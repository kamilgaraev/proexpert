<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use Tests\Support\EstimateGeneration\EstimateGenerationApplicationTestCase;

class EstimateGenerationPipelineQualityTest extends EstimateGenerationApplicationTestCase
{
    public function test_priced_work_without_snapshot_is_marked_not_calculated(): void
    {
        $items = app(EstimatePricingService::class)->price([[
            'key' => 'foundation.zero',
            'item_type' => 'priced_work',
            'name' => 'Foundation zero price',
            'unit' => 'm3',
            'quantity' => 1,
            'materials' => [['total_price' => 0]],
            'labor' => [],
            'machinery' => [],
            'pricing_status' => 'calculated',
            'validation_flags' => [],
        ]]);

        $this->assertSame(0, $items[0]['total_cost']);
        $this->assertSame('not_calculated', $items[0]['pricing_status']);
        $this->assertSame('missing_price_snapshot', $items[0]['pricing_blocker']);
        $this->assertContains('missing_price_snapshot', $items[0]['validation_flags']);
    }

    public function test_generated_house_without_source_takeoff_does_not_invent_priced_work(): void
    {
        $analysis = [
            'object' => [
                'description' => 'Жилой дом 150 м2, Московская область, 1 квартал 2026 года',
                'building_type' => 'Жилой',
                'region' => 'Московская область',
                'area' => 150,
            ],
            'detected_structure' => [
                'scopes' => [
                    ['title' => 'Фундамент', 'scope_type' => 'foundation', 'source_refs' => []],
                    ['title' => 'Электрика', 'scope_type' => 'electrical', 'source_refs' => []],
                ],
            ],
        ];

        $localEstimate = [
            'key' => 'foundation',
            'title' => 'Фундамент',
            'scope_type' => 'foundation',
            'target_items_min' => 12,
            'sections' => [[
                'key' => 'foundation-section',
                'title' => 'Фундамент',
                'construction_part' => 'foundation',
                'source_refs' => [],
            ]],
        ];
        $items = app(NormativeWorkItemPlannerService::class)->build(
            $localEstimate,
            $localEstimate['sections'][0],
            $analysis,
        );
        $items = app(ResourceAssemblyService::class)->enrich($items, ['scope_type' => 'foundation']);
        $items = app(EstimatePricingService::class)->price($items);
        $pricedItems = array_values(array_filter($items, static fn (array $item): bool => ($item['item_type'] ?? null) === 'priced_work'));

        $this->assertSame([], $pricedItems);
    }
}
