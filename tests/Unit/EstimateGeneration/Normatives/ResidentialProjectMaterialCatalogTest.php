<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialProjectMaterialCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResidentialProjectMaterialCatalogTest extends TestCase
{
    #[Test]
    public function every_residential_electrical_scenario_has_an_exact_project_material(): void
    {
        $scenarios = new ResidentialMaterialScenarioCatalog;
        $catalog = new ResidentialProjectMaterialCatalog($scenarios);
        $expected = [
            'electrical.main_cable' => '21.1.06.09-0154',
            'electrical.power_lines' => '21.1.06.09-0152',
            'lighting.lines' => '21.1.06.09-0151',
            'electrical.panel' => '20.4.04.02-0003',
            'electrical.outlets' => '20.4.03.06-1036',
            'electrical.switches' => '20.4.01.02-1023',
            'lighting.fixtures' => '59.1.20.03-0798',
        ];

        foreach ($expected as $workItemKey => $resourceCode) {
            $requirement = $catalog->requirementForIntent([
                'specialization_scenario' => $scenarios->issue($workItemKey, 'residential'),
            ]);

            self::assertSame($resourceCode, $requirement['resource_code'] ?? null, $workItemKey);
        }
    }

    #[Test]
    public function work_scenario_and_material_selection_keep_their_respective_assumption_identities(): void
    {
        $scenarios = new ResidentialMaterialScenarioCatalog;
        $catalog = new ResidentialProjectMaterialCatalog($scenarios);
        $expected = [
            'electrical.main_cable' => ['residential_feeder_cable_clips', 'residential_main_cable_vvgng_ls_3x6_with_waste_5_percent'],
            'electrical.power_lines' => ['residential_power_wiring_channels', 'residential_power_cable_vvgng_ls_3x2_5_with_waste_5_percent'],
            'lighting.lines' => ['residential_lighting_wiring_chases', 'residential_lighting_cable_vvgng_ls_3x1_5_with_waste_5_percent'],
            'electrical.panel' => ['residential_recessed_lighting_panel', 'residential_recessed_distribution_panel_24_modules'],
            'electrical.outlets' => ['residential_recessed_socket', 'residential_recessed_grounded_socket_with_shutter'],
            'electrical.switches' => ['residential_recessed_single_switch', 'residential_recessed_single_switch'],
            'lighting.fixtures' => ['residential_ceiling_luminaire', 'residential_led_ceiling_luminaire_18w'],
        ];

        foreach ($expected as $workItemKey => [$scenarioAssumption, $materialAssumption]) {
            $scenario = $scenarios->issue($workItemKey, 'residential');
            self::assertIsArray($scenario);
            $requirement = $catalog->requirementForIntent(['specialization_scenario' => $scenario]);

            self::assertSame($scenarioAssumption, $scenario['assumption_code'], $workItemKey);
            self::assertSame($materialAssumption, $requirement['assumption_code'] ?? null, $workItemKey);
        }
    }

    #[Test]
    public function only_a_signed_residential_scenario_can_request_a_project_material(): void
    {
        $scenarios = new ResidentialMaterialScenarioCatalog;
        $catalog = new ResidentialProjectMaterialCatalog($scenarios);
        $scenario = $scenarios->issue('electrical.power_lines', 'residential');

        self::assertIsArray($scenario);
        self::assertSame('21.1.06.09-0152', $catalog->requirementForIntent([
            'specialization_scenario' => $scenario,
        ])['resource_code'] ?? null);
        self::assertNull($catalog->requirementForIntent([
            'specialization_scenario' => [...$scenario, 'signature' => 'tampered'],
        ]));
    }

    #[Test]
    public function cable_price_is_converted_from_catalog_thousand_metres_and_keeps_provenance(): void
    {
        $scenarios = new ResidentialMaterialScenarioCatalog;
        $catalog = new ResidentialProjectMaterialCatalog($scenarios);
        $requirement = $catalog->requirementForIntent([
            'specialization_scenario' => $scenarios->issue('lighting.lines', 'residential'),
        ]);

        self::assertIsArray($requirement);
        $resource = $catalog->resourceFromPriceRow($requirement, (object) [
            'price_id' => 31,
            'resource_code' => '21.1.06.09-0151',
            'resource_name' => 'Кабель силовой с медными жилами ВВГнг(A)-LS 3х1,5ок(N, PE)-660',
            'unit' => '1000 м',
            'base_price' => '72500.00',
            'price_source' => 'regional_catalog',
            'price_source_version' => 'region-2026-q2',
            'construction_resource_id' => 91,
        ]);

        self::assertIsArray($resource);
        self::assertSame('72500', $resource['unit_price']);
        self::assertSame(0.00105, $resource['quantity']);
        self::assertSame('regional_catalog', $resource['price_source']);
        self::assertSame('1000 м', $resource['project_material_requirement']['source_price_unit']);
        self::assertSame('1', $resource['project_material_requirement']['price_conversion_factor']);
        self::assertSame('project_material_candidate_pool:v2', $resource['project_material_requirement']['candidate_pool_version']);
        self::assertSame([31], $resource['project_material_requirement']['candidate_resource_price_ids']);
    }

    #[Test]
    public function missing_preferred_panel_price_uses_regional_median_from_the_same_semantic_group(): void
    {
        $scenarios = new ResidentialMaterialScenarioCatalog;
        $catalog = new ResidentialProjectMaterialCatalog($scenarios);
        $requirement = $catalog->requirementForIntent([
            'specialization_scenario' => $scenarios->issue('electrical.panel', 'residential'),
        ]);

        self::assertIsArray($requirement);
        $resource = $catalog->resourceFromPriceRows($requirement, [
            (object) [
                'price_id' => 40,
                'resource_code' => '20.4.04.02-0100',
                'resource_name' => 'Щиток осветительный навесной на 12 модулей',
                'unit' => 'шт',
                'base_price' => '1800.00',
                'price_source' => 'fsnb_base',
                'price_source_version' => 'fsnb-2022',
            ],
            (object) [
                'price_id' => 41,
                'resource_code' => '20.4.04.02-0101',
                'resource_name' => 'Щиток осветительный встраиваемый на 24 модуля',
                'unit' => 'шт',
                'base_price' => '3200.00',
                'price_source' => 'regional_catalog',
                'price_source_version' => 'region-2026-q2',
            ],
        ]);

        self::assertIsArray($resource);
        self::assertSame('20.4.04.02-0101', $resource['code']);
        self::assertSame('3200', $resource['unit_price']);
        self::assertSame('regional_catalog', $resource['price_source']);
        self::assertSame('20.4.04.02-0003', $resource['project_material_requirement']['preferred_resource_code']);
        self::assertSame('semantic_group_median', $resource['project_material_requirement']['selection_policy']);
        self::assertSame([41], $resource['project_material_requirement']['candidate_resource_price_ids']);
    }

    #[Test]
    public function missing_new_luminaire_group_uses_a_strict_semantic_catalog_equivalent(): void
    {
        $scenarios = new ResidentialMaterialScenarioCatalog;
        $catalog = new ResidentialProjectMaterialCatalog($scenarios);
        $requirement = $catalog->requirementForIntent([
            'specialization_scenario' => $scenarios->issue('lighting.fixtures', 'residential'),
        ]);

        self::assertIsArray($requirement);
        $resource = $catalog->resourceFromPriceRows($requirement, [
            (object) [
                'price_id' => 51,
                'resource_code' => '20.3.02.03-0101',
                'resource_name' => 'Светильник промышленный светодиодный подвесной IP65',
                'unit' => 'шт',
                'base_price' => '4100.00',
                'price_source' => 'regional_catalog',
                'price_source_version' => 'region-2026-q2',
            ],
            (object) [
                'price_id' => 52,
                'resource_code' => '20.3.02.03-0102',
                'resource_name' => 'Светильник потолочный светодиодный накладной IP20, 18 Вт',
                'unit' => 'шт',
                'base_price' => '1850.00',
                'price_source' => 'fsnb_base',
                'price_source_version' => 'fsnb-2022',
            ],
        ]);

        self::assertIsArray($resource);
        self::assertSame('20.3.02.03-0102', $resource['code']);
        self::assertSame('1850', $resource['unit_price']);
        self::assertSame('semantic_catalog_attributes_median', $resource['project_material_requirement']['selection_policy']);
        self::assertSame(
            'residential_ceiling_luminaire_attributes:v1',
            $resource['project_material_requirement']['semantic_eligibility_policy'],
        );
        self::assertSame([52], $resource['project_material_requirement']['candidate_resource_price_ids']);
    }

    #[Test]
    public function public_building_luminaire_is_not_a_safe_residential_fallback(): void
    {
        $scenarios = new ResidentialMaterialScenarioCatalog;
        $catalog = new ResidentialProjectMaterialCatalog($scenarios);
        $requirement = $catalog->requirementForIntent([
            'specialization_scenario' => $scenarios->issue('lighting.fixtures', 'residential'),
        ]);

        self::assertIsArray($requirement);
        self::assertNull($catalog->resourceFromPriceRows($requirement, [(object) [
            'price_id' => 53,
            'resource_code' => '20.3.03.07-0094',
            'resource_name' => 'Светильник светодиодный потолочный накладной для общественных зданий, IP54, 595х595 мм, 35 Вт',
            'unit' => 'шт',
            'base_price' => '2925.00',
            'price_source' => 'regional_catalog',
            'price_source_version' => 'region-2026-q2',
        ]]));
    }

    #[Test]
    public function semantic_median_uses_only_safe_residential_luminaires(): void
    {
        $scenarios = new ResidentialMaterialScenarioCatalog;
        $catalog = new ResidentialProjectMaterialCatalog($scenarios);
        $requirement = $catalog->requirementForIntent([
            'specialization_scenario' => $scenarios->issue('lighting.fixtures', 'residential'),
        ]);

        self::assertIsArray($requirement);
        $resource = $catalog->resourceFromPriceRows($requirement, [
            (object) [
                'price_id' => 54,
                'resource_code' => '20.3.03.07-0094',
                'resource_name' => 'Светильник светодиодный потолочный для общественных зданий, IP54, 595х595 мм, 35 Вт',
                'unit' => 'шт',
                'base_price' => '1000.00',
                'price_source' => 'regional_catalog',
                'price_source_version' => 'region-2026-q2',
            ],
            (object) [
                'price_id' => 55,
                'resource_code' => '20.3.02.03-0102',
                'resource_name' => 'Светильник потолочный светодиодный накладной IP20, 18 Вт',
                'unit' => 'шт',
                'base_price' => '1850.00',
                'price_source' => 'regional_catalog',
                'price_source_version' => 'region-2026-q2',
            ],
        ]);

        self::assertIsArray($resource);
        self::assertSame('20.3.02.03-0102', $resource['code']);
        self::assertSame([55], $resource['project_material_requirement']['candidate_resource_price_ids']);
    }

    #[Test]
    public function all_unsafe_semantic_luminaires_fail_closed(): void
    {
        $scenarios = new ResidentialMaterialScenarioCatalog;
        $catalog = new ResidentialProjectMaterialCatalog($scenarios);
        $requirement = $catalog->requirementForIntent([
            'specialization_scenario' => $scenarios->issue('lighting.fixtures', 'residential'),
        ]);

        self::assertIsArray($requirement);
        self::assertNull($catalog->resourceFromPriceRows($requirement, [
            (object) [
                'price_id' => 56,
                'resource_code' => '20.3.03.07-0094',
                'resource_name' => 'Светильник светодиодный потолочный для общественных зданий, IP54, 595х595 мм, 35 Вт',
                'unit' => 'шт',
                'base_price' => '1000.00',
                'price_source' => 'regional_catalog',
                'price_source_version' => 'region-2026-q2',
            ],
            (object) [
                'price_id' => 57,
                'resource_code' => '20.3.02.03-0102',
                'resource_name' => 'Светильник промышленный потолочный светодиодный IP65, 18 Вт',
                'unit' => 'шт',
                'base_price' => '1850.00',
                'price_source' => 'regional_catalog',
                'price_source_version' => 'region-2026-q2',
            ],
        ]));
    }

    #[Test]
    public function mismatched_price_identity_fails_closed(): void
    {
        $scenarios = new ResidentialMaterialScenarioCatalog;
        $catalog = new ResidentialProjectMaterialCatalog($scenarios);
        $requirement = $catalog->requirementForIntent([
            'specialization_scenario' => $scenarios->issue('electrical.outlets', 'residential'),
        ]);

        self::assertIsArray($requirement);
        self::assertNull($catalog->resourceFromPriceRow($requirement, (object) [
            'price_id' => 32,
            'resource_code' => '20.4.03.05-0004',
            'resource_name' => 'Розетка открытой проводки',
            'unit' => 'шт',
            'base_price' => '100',
            'price_source' => 'regional_catalog',
            'price_source_version' => 'region-2026-q2',
        ]));
    }
}
