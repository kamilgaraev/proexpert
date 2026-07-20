<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Application;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\AssembleMatchedResources;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialProjectMaterialCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveRegionalPrice;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use PHPUnit\Framework\TestCase;

final class AssembleMatchedResourcesTest extends TestCase
{
    public function test_appends_pinned_project_material_once_and_preserves_used_price(): void
    {
        $catalog = new ResidentialProjectMaterialCatalog;
        $scenario = (new ResidentialMaterialScenarioCatalog)->issue('electrical.power_lines', 'residential');
        self::assertIsArray($scenario);
        $requirement = $catalog->requirementForIntent(['specialization_scenario' => $scenario]);
        self::assertIsArray($requirement);
        $resource = $catalog->resourceFromPriceRow($requirement, (object) [
            'price_id' => 41,
            'construction_resource_id' => 9,
            'resource_code' => '21.1.06.09-0152',
            'resource_name' => 'Кабель ВВГнг(A)-LS 3х2,5',
            'unit' => '1000 м',
            'base_price' => '72000',
            'price_source' => 'regional_catalog',
            'price_source_version' => 'region-16-q2-2026',
        ]);
        self::assertIsArray($resource);
        $data = $this->data($scenario, [[
            'work_item_key' => 'electrical.power_lines',
            'requirement' => $requirement,
            'status' => 'priced',
            'resource' => $resource,
        ]]);

        $assembler = new AssembleMatchedResources($catalog);
        $first = $assembler->handle($data)['data'];
        $second = $assembler->handle($first)['data'];
        $item = $second['local_estimates'][0]['sections'][0]['work_items'][0];

        self::assertCount(1, $item['materials']);
        self::assertSame(0.105, $item['materials'][0]['quantity']);
        self::assertSame(72000.0, $item['materials'][0]['unit_price']);
        self::assertSame(7560.0, $item['materials'][0]['total_price']);
        self::assertSame('regional_catalog', $item['materials'][0]['price_source']);
        self::assertSame('region-16-q2-2026', $item['materials'][0]['price_source_version']);
        self::assertSame('calculated', $item['pricing_status']);

        $priced = (new EstimatePricingService(new ResolveRegionalPrice(static fn (int $priceId): array => [
            'id' => $priceId,
            'base_price' => '72000',
            'regional_price_version_id' => 5,
            'region_id' => 16,
            'price_zone_id' => 1,
            'period_id' => 2,
            'source_type' => 'regional_catalog',
            'currency' => 'RUB',
        ])))->price([$item], [
            'estimate_regional_price_version_id' => 5,
            'region_id' => 16,
            'price_zone_id' => 1,
            'period_id' => 2,
        ])[0];

        self::assertSame('7560.00', $priced['materials_cost']);
        self::assertSame('7560.00', $priced['total_cost']);
    }

    public function test_missing_required_project_material_price_blocks_work_item(): void
    {
        $scenario = (new ResidentialMaterialScenarioCatalog)->issue('electrical.power_lines', 'residential');
        self::assertIsArray($scenario);

        $result = (new AssembleMatchedResources)->handle($this->data($scenario, []))['data'];
        $item = $result['local_estimates'][0]['sections'][0]['work_items'][0];

        self::assertSame('not_calculated', $item['pricing_status']);
        self::assertSame('project_material_price_missing', $item['pricing_blocker']);
        self::assertContains('project_material_price_missing', $item['validation_flags']);
    }

    public function test_appends_semantic_group_fallback_with_its_actual_catalog_identity(): void
    {
        $catalog = new ResidentialProjectMaterialCatalog;
        $scenario = (new ResidentialMaterialScenarioCatalog)->issue('electrical.panel', 'residential');
        self::assertIsArray($scenario);
        $requirement = $catalog->requirementForIntent(['specialization_scenario' => $scenario]);
        self::assertIsArray($requirement);
        $resource = $catalog->resourceFromPriceRows($requirement, [(object) [
            'price_id' => 51,
            'construction_resource_id' => 19,
            'resource_code' => '20.4.04.02-0101',
            'resource_name' => 'Щиток осветительный встраиваемый на 24 модуля',
            'unit' => 'шт',
            'base_price' => '3200',
            'price_source' => 'fsnb_base',
            'price_source_version' => 'fsnb-2022',
        ]]);
        self::assertIsArray($resource);

        $result = (new AssembleMatchedResources($catalog))->handle($this->data($scenario, [[
            'work_item_key' => 'electrical.panel',
            'requirement' => $requirement,
            'status' => 'priced',
            'resource' => $resource,
        ]]))['data'];
        $item = $result['local_estimates'][0]['sections'][0]['work_items'][0];

        self::assertSame('20.4.04.02-0101', $item['materials'][0]['code'] ?? null);
        self::assertSame(3200.0, $item['materials'][0]['unit_price'] ?? null);
        self::assertSame('semantic_group_median', $item['materials'][0]['project_material_selection']['selection_policy'] ?? null);
        self::assertSame('calculated', $item['pricing_status']);
    }

    public function test_tampered_pinned_project_material_price_is_rejected(): void
    {
        $catalog = new ResidentialProjectMaterialCatalog;
        $scenario = (new ResidentialMaterialScenarioCatalog)->issue('electrical.power_lines', 'residential');
        self::assertIsArray($scenario);
        $requirement = $catalog->requirementForIntent(['specialization_scenario' => $scenario]);
        self::assertIsArray($requirement);
        $resource = $catalog->resourceFromPriceRow($requirement, (object) [
            'price_id' => 41,
            'construction_resource_id' => 9,
            'resource_code' => '21.1.06.09-0152',
            'resource_name' => 'Кабель ВВГнг(A)-LS 3х2,5',
            'unit' => '1000 м',
            'base_price' => '72000',
            'price_source' => 'regional_catalog',
            'price_source_version' => 'region-16-q2-2026',
        ]);
        self::assertIsArray($resource);

        $result = (new AssembleMatchedResources($catalog))->handle($this->data($scenario, [[
            'work_item_key' => 'electrical.power_lines',
            'requirement' => $requirement,
            'status' => 'priced',
            'resource' => [...$resource, 'unit_price' => '1'],
        ]]))['data'];
        $item = $result['local_estimates'][0]['sections'][0]['work_items'][0];

        self::assertSame('project_material_price_missing', $item['pricing_blocker']);
        self::assertSame([], $item['materials']);
    }

    /** @return array<string, mixed> */
    private function data(array $scenario, array $supplementaryMaterials): array
    {
        return [
            'supplementary_materials' => $supplementaryMaterials,
            'local_estimates' => [[
                'sections' => [[
                    'work_items' => [[
                        'key' => 'electrical.power_lines',
                        'quantity' => 100,
                        'normative_rate_code' => $scenario['normative_rate_code'],
                        'specialization_scenario' => $scenario,
                        'pricing_status' => 'calculated',
                        'validation_flags' => [],
                        'materials' => [],
                        'labor' => [],
                        'machinery' => [],
                        'other_resources' => [],
                    ]],
                ]],
            ]],
        ];
    }
}
