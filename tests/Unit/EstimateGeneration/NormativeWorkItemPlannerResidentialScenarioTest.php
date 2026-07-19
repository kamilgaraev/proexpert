<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\WallData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeWorkIntentFactory;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\ResidentialQuantityScenarioCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeScopeRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NormativeWorkItemPlannerResidentialScenarioTest extends TestCase
{
    #[Test]
    public function current_residential_scenario_exposes_traceable_required_work_items(): void
    {
        foreach ([
            ['stairs', 'stairs', 'stairs.flights', 'm2', '8.000000'],
            ['roof', 'roof', 'roof.area', 'm2', '152.955000'],
            ['openings', 'openings', 'openings.windows', 'm2', '23.136000'],
            ['electrical', 'electrical', 'electrical.main_cable', 'm', '77.120000'],
            ['lighting', 'electrical', 'lighting.lines', 'm', '154.240000'],
            ['plumbing', 'plumbing', 'plumbing.pipe', 'm', '67.480000'],
            ['sewerage', 'sewerage', 'sewerage.pipe', 'm', '48.200000'],
            ['heating', 'heating', 'heating.pipe', 'm', '96.400000'],
            ['ventilation', 'ventilation', 'ventilation.air_exchange', 'm2', '23.136000'],
        ] as [$package, $scope, $quantityKey, $unit, $amount]) {
            $analysis = [
                'object' => ['object_type' => 'house', 'roof_type' => 'pitched'],
                'document_context' => ['canonical_building_quantities' => [
                    $this->currentScenarioQuantity($quantityKey, $unit, $amount)->toArray(),
                ]],
            ];
            $estimate = $this->estimate($package, $scope);

            $items = $this->planner()->build($estimate, $estimate['sections'][0], $analysis);

            self::assertContains($quantityKey, array_column($items, 'quantity_formula'), $quantityKey);
        }
    }

    #[Test]
    public function preliminary_house_items_use_semantically_verified_norms_with_compatible_units(): void
    {
        foreach ([
            ['stairs', 'stairs', 'stairs.flights', 'm2', '8.000000', '10-01-052-02'],
            ['openings', 'openings', 'openings.windows', 'm2', '23.136000', '10-01-034-05'],
            ['electrical', 'electrical', 'electrical.grounding', 'm', '42.576989', '08-02-472-01'],
            ['plumbing', 'plumbing', 'sanitary.waterproofing', 'm2', '12.980000', '11-01-004-05'],
            ['plumbing', 'plumbing', 'sanitary.tile', 'm2', '39.497496', '15-01-019-05'],
        ] as [$package, $scope, $quantityKey, $unit, $amount, $normCode]) {
            $analysis = [
                'object' => ['object_type' => 'house'],
                'document_context' => ['canonical_building_quantities' => [
                    $this->currentScenarioQuantity($quantityKey, $unit, $amount)->toArray(),
                ]],
            ];
            $estimate = $this->estimate($package, $scope);

            $items = $this->planner()->build($estimate, $estimate['sections'][0], $analysis);
            $item = array_column($items, null, 'quantity_formula')[$quantityKey] ?? null;

            self::assertIsArray($item, $quantityKey);
            self::assertSame($normCode, $item['normative_rate_code'], $quantityKey);
            self::assertContains('preliminary_material_assumption', $item['validation_flags'], $quantityKey);
        }
    }

    #[Test]
    public function estimated_residential_scenario_does_not_expose_direct_opening_takeoffs(): void
    {
        $analysis = [
            'object' => ['object_type' => 'house'],
            'document_context' => ['canonical_building_quantities' => [
                $this->scenarioQuantity('openings.doors', 'm2', '9.000000')->toArray(),
            ]],
        ];
        $estimate = $this->estimate('openings', 'openings');

        $items = $this->planner()->build($estimate, $estimate['sections'][0], $analysis);
        self::assertNotContains('openings.doors', array_column($items, 'quantity_formula'));
    }

    #[Test]
    public function estimated_residential_scenario_does_not_expose_sanitary_takeoffs(): void
    {
        $analysis = [
            'object' => ['object_type' => 'house'],
            'document_context' => ['canonical_building_quantities' => [
                $this->scenarioQuantity('sanitary.points', 'pcs', '2.000000')->toArray(),
            ]],
        ];
        $estimate = $this->estimate('plumbing', 'plumbing');

        $items = $this->planner()->build($estimate, $estimate['sections'][0], $analysis);

        self::assertNotContains('sanitary.points', array_column($items, 'quantity_formula'));
    }

    #[Test]
    public function residential_electrical_scenario_does_not_create_cable_trays(): void
    {
        $analysis = [
            'object' => ['object_type' => 'house'],
            'document_context' => ['canonical_building_quantities' => [
                $this->scenarioQuantity('electrical.main_cable', 'm', '77.120000')->toArray(),
                $this->scenarioQuantity('electrical.power_lines', 'm', '154.240000')->toArray(),
                $this->scenarioQuantity('electrical.grounding', 'm', '42.576000')->toArray(),
            ]],
        ];
        $estimate = $this->estimate('electrical', 'electrical');

        $items = $this->planner()->build($estimate, $estimate['sections'][0], $analysis);

        self::assertNotContains('electrical.trays', array_column($items, 'quantity_formula'));
        self::assertNotContains('electrical.main_cable', array_column($items, 'quantity_formula'));
        self::assertNotContains('electrical.power_lines', array_column($items, 'quantity_formula'));
        self::assertNotContains('electrical.grounding', array_column($items, 'quantity_formula'));
    }

    #[Test]
    public function residential_ventilation_uses_catalog_owned_small_duct_normative_family(): void
    {
        $analysis = [
            'object' => ['object_type' => 'house'],
            'document_context' => ['canonical_building_quantities' => [
                $this->evidencedQuantity('ventilation.air_exchange', 'm2', '57.840000')->toArray(),
            ]],
        ];
        $estimate = $this->estimate('ventilation', 'ventilation');

        $items = $this->planner()->build($estimate, $estimate['sections'][0], $analysis);
        $item = array_column($items, null, 'quantity_formula')['ventilation.air_exchange'];

        self::assertSame('Приточно-вытяжная вентиляция', $item['name']);
        self::assertSame('монтаж воздуховодов', $item['normative_search_text']);
        self::assertSame('20-01-001-01', $item['normative_rate_code']);
        self::assertSame(
            'residential_small_galvanized_ducts',
            $item['metadata']['material_assumption']['code'] ?? null,
        );
        self::assertContains('preliminary_material_assumption', $item['validation_flags']);
    }

    #[Test]
    public function trusted_document_material_overrides_preliminary_residential_material_scenarios(): void
    {
        foreach ([
            [
                'walls',
                'walls',
                'walls.external_volume',
                'm3',
                'Кладка стен кирпичных наружных простых',
                '08-02-001-01',
                'Наружные стены из кирпича',
                'document',
            ],
            [
                'finish_finishing',
                'finishing',
                'finish.floor',
                'm2',
                'Устройство покрытий полов из линолеума',
                null,
                'Покрытие пола — линолеум',
                'building_model',
            ],
            [
                'finish_finishing',
                'finishing',
                'finish.baseboard',
                'm',
                'Устройство деревянных плинтусов',
                null,
                'Плинтус деревянный',
                'user_confirmation',
            ],
        ] as [$package, $scope, $quantityKey, $unit, $search, $code, $evidenceText, $source]) {
            $analysis = [
                'object' => ['object_type' => 'house'],
                'document_context' => [
                    'canonical_building_quantities' => [
                        $this->evidencedQuantity($quantityKey, $unit, '12.000000')->toArray(),
                    ],
                    'specialization_evidence' => [
                        $quantityKey => [[
                            'text' => $evidenceText,
                            'source' => $source,
                            'evidence_refs' => ['document:material-schedule'],
                            'normative_search_text' => $search,
                            ...($code !== null ? ['normative_rate_code' => $code] : []),
                        ]],
                    ],
                ],
            ];
            $estimate = $this->estimate($package, $scope);

            $items = $this->planner()->build($estimate, $estimate['sections'][0], $analysis);
            $item = array_values(array_filter(
                $items,
                static fn (array $candidate): bool => ($candidate['quantity_formula'] ?? null) === $quantityKey,
            ))[0] ?? null;

            self::assertIsArray($item, $quantityKey);
            self::assertSame($search, $item['normative_search_text'], $quantityKey);
            self::assertSame($code, $item['normative_rate_code'], $quantityKey);
            self::assertArrayNotHasKey('specialization_scenario', $item, $quantityKey);
            self::assertArrayNotHasKey('material_assumption', $item['metadata'], $quantityKey);
            self::assertSame($evidenceText, $item['specialization_evidence'][0]['text'] ?? null, $quantityKey);
            self::assertNotContains('preliminary_material_assumption', $item['validation_flags'], $quantityKey);
        }
    }

    #[Test]
    public function normalized_building_model_wall_material_suppresses_preliminary_wall_scenario(): void
    {
        $model = new NormalizedBuildingModelData(
            unit: 'm',
            scaleStatus: 'confirmed',
            scaleMetersPerUnit: 1.0,
            floors: [new FloorData(
                key: 'floor-1',
                elevationM: 0.0,
                heightM: 3.0,
                rooms: [],
                walls: [new WallData(
                    key: 'wall-exterior-1',
                    start: [0.0, 0.0],
                    end: [10.0, 0.0],
                    thicknessM: 0.38,
                    heightM: 3.0,
                    evidenceIds: [14201],
                    confidence: 0.94,
                    geometryCertainty: 'confirmed',
                    type: 'external',
                    material: 'кирпич',
                )],
                openings: [],
                engineeringElements: [],
                evidenceIds: [14201],
                confidence: 0.94,
                geometryCertainty: 'confirmed',
            )],
            assumptions: [],
            modelVersion: 'building-model:v1',
        );
        $analysis = [
            'object' => ['object_type' => 'house'],
            'normalized_building_model' => $model->toArray(),
            'document_context' => ['canonical_building_quantities' => [
                $this->evidencedQuantity('walls.external_volume', 'm3', '11.400000', ['14201'])->toArray(),
            ]],
        ];
        $estimate = $this->estimate('walls', 'walls');

        $items = $this->planner()->build($estimate, $estimate['sections'][0], $analysis);
        $item = array_values(array_filter(
            $items,
            static fn (array $candidate): bool => ($candidate['quantity_formula'] ?? null) === 'walls.external_volume',
        ))[0] ?? null;

        self::assertIsArray($item);
        self::assertStringContainsString('кирпич', mb_strtolower((string) $item['normative_search_text']));
        self::assertNull($item['normative_rate_code']);
        self::assertArrayNotHasKey('specialization_scenario', $item);
        self::assertSame('building_model', $item['specialization_evidence'][0]['source'] ?? null);
        self::assertSame(['14201'], $item['specialization_evidence'][0]['evidence_refs'] ?? null);

        $intent = (new NormativeWorkIntentFactory(
            new WorkIntentClassifier(new NormativeScopeRuleCatalog),
        ))->intent($item, [
            'organization_id' => 1,
            'project_id' => 89,
            'session_id' => 58,
            'scope_type' => 'walls',
            'object_type' => 'house',
            'applicability_date' => '2026-07-17',
            'source_refs' => $item['source_refs'],
        ], 'fsnb-2026.1');

        self::assertSame(['14201'], $intent->sourceEvidence);
        self::assertSame(['14201'], $intent->specializationEvidence[0]['evidence_refs'] ?? null);
        self::assertNull($intent->specializationScenario);
    }

    #[Test]
    public function unknown_roof_type_uses_only_a_generic_roof_definition(): void
    {
        $analysis = [
            'object' => ['object_type' => 'house'],
            'document_context' => ['canonical_building_quantities' => [
                $this->scenarioQuantity('roof.area', 'm2', '113.300000')->toArray(),
            ]],
        ];
        $estimate = $this->estimate('roof', 'roof');

        $items = $this->planner()->build($estimate, $estimate['sections'][0], $analysis);

        self::assertNotContains('roof.area', array_column($items, 'quantity_formula'));
    }

    #[Test]
    public function flat_roof_type_uses_only_flat_roof_quantities(): void
    {
        $analysis = [
            'object' => ['object_type' => 'house', 'roof_type' => 'flat'],
            'document_context' => ['canonical_building_quantities' => [
                $this->scenarioQuantity('roof.flat_area', 'm2', '113.300000')->toArray(),
            ]],
        ];
        $estimate = $this->estimate('roof', 'roof');

        $items = $this->planner()->build($estimate, $estimate['sections'][0], $analysis);

        self::assertNotContains('roof.flat_area', array_column($items, 'quantity_formula'));
    }

    #[Test]
    public function pitched_roof_type_uses_pitched_roof_quantities(): void
    {
        $analysis = [
            'object' => ['object_type' => 'house', 'roof_type' => 'pitched'],
            'document_context' => ['canonical_building_quantities' => [
                $this->scenarioQuantity('roof.area', 'm2', '152.955000')->toArray(),
            ]],
        ];
        $estimate = $this->estimate('roof', 'roof');

        $items = $this->planner()->build($estimate, $estimate['sections'][0], $analysis);

        self::assertNotContains('roof.area', array_column($items, 'quantity_formula'));
    }

    #[Test]
    public function current_pitched_roof_scenario_exposes_only_normable_supported_works(): void
    {
        $analysis = [
            'object' => ['object_type' => 'house', 'roof_type' => 'pitched'],
            'document_context' => ['canonical_building_quantities' => [
                $this->currentScenarioQuantity('roof.rafters', 'm2', '152.955000')->toArray(),
                $this->currentScenarioQuantity('roof.area', 'm2', '152.955000')->toArray(),
                $this->currentScenarioQuantity('roof.gutter', 'm', '46.834688')->toArray(),
            ]],
        ];
        $estimate = $this->estimate('roof', 'roof');

        $items = $this->planner()->build($estimate, $estimate['sections'][0], $analysis);

        self::assertSame(
            ['Монтаж кровельного покрытия'],
            array_column($items, 'name'),
        );
        self::assertSame(['roof.area'], array_column($items, 'quantity_formula'));
        self::assertSame(['12-01-023-01'], array_column($items, 'normative_rate_code'));
    }

    private function scenarioQuantity(string $key, string $unit, string $amount): QuantityData
    {
        return new QuantityData(
            key: $key,
            unit: $unit,
            amount: $amount,
            formulaKey: 'residential_preliminary.'.$key,
            formulaVersion: '1.0.0',
            formulaInputs: ['scenario' => [
                'id' => 'residential_preliminary_scenario:v1',
                'version' => '1.0.0',
                'confidence' => 0.55,
                'warnings' => ['preliminary_quantity_scenario'],
            ]],
            source: QuantitySource::Estimated,
            evidenceIds: ['room:1'],
            modelVersion: 'building-model:v1',
            assumptions: ['residential_preliminary_scenario:v1'],
        );
    }

    private function currentScenarioQuantity(string $key, string $unit, string $amount): QuantityData
    {
        return new QuantityData(
            key: $key,
            unit: $unit,
            amount: $amount,
            formulaKey: 'residential_preliminary.'.$key,
            formulaVersion: ResidentialQuantityScenarioCatalog::VERSION,
            formulaInputs: ['scenario' => [
                'id' => ResidentialQuantityScenarioCatalog::SCENARIO_ID,
                'version' => ResidentialQuantityScenarioCatalog::VERSION,
                'confidence' => 0.62,
                'warnings' => ['preliminary_quantity_scenario'],
            ]],
            source: QuantitySource::Estimated,
            evidenceIds: ['room:1'],
            modelVersion: 'building-model:v1',
            assumptions: [ResidentialQuantityScenarioCatalog::SCENARIO_ID],
        );
    }

    /** @param list<string> $evidenceIds */
    private function evidencedQuantity(string $key, string $unit, string $amount, array $evidenceIds = ['document:ventilation-schedule']): QuantityData
    {
        return new QuantityData(
            key: $key,
            unit: $unit,
            amount: $amount,
            formulaKey: 'document.takeoff.'.$key,
            formulaVersion: '1.0.0',
            formulaInputs: ['takeoff' => 'measured'],
            source: QuantitySource::Evidenced,
            evidenceIds: $evidenceIds,
            modelVersion: 'building-model:v1',
        );
    }

    private function estimate(string $key, string $scope): array
    {
        return [
            'key' => $key,
            'title' => $key,
            'scope_type' => $scope,
            'source_refs' => [],
            'sections' => [[
                'key' => $key.'-section',
                'title' => $key,
                'construction_part' => $scope,
                'source_refs' => [],
            ]],
        ];
    }

    private function planner(): NormativeWorkItemPlannerService
    {
        return new NormativeWorkItemPlannerService(
            new ProjectDocumentNormativeReferenceExtractor,
            new EstimatorScopeInferenceService,
        );
    }
}
