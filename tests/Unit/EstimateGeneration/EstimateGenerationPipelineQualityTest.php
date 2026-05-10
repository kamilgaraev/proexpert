<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDecompositionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use App\BusinessModules\Addons\EstimateGeneration\Services\WorkItemGenerationService;
use Tests\TestCase;

class EstimateGenerationPipelineQualityTest extends TestCase
{
    public function test_generated_house_estimate_requires_normative_review_instead_of_market_prices(): void
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

        $decomposition = app(EstimateDecompositionService::class)->decompose($analysis);
        $items = app(WorkItemGenerationService::class)->build($decomposition[0], $analysis);
        $items = app(ResourceAssemblyService::class)->enrich($items, ['scope_type' => 'foundation']);
        $items = app(EstimatePricingService::class)->price($items);

        $this->assertNotEmpty($items);
        $this->assertEquals(0, array_sum(array_column($items, 'total_cost')));
        $this->assertNotContains('market_price_used', $items[0]['validation_flags']);
        $this->assertContains('requires_normative_review', $items[0]['validation_flags']);
    }
}
