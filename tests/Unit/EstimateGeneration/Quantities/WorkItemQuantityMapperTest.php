<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\CanonicalEvidenceJson;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceUnit;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\ResidentialQuantityScenarioCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkItemQuantityMapperTest extends TestCase
{
    #[Test]
    public function finish_floor_uses_confirmed_floor_area_as_estimation_basis(): void
    {
        $quantity = (new WorkItemQuantityMapper)->map('finish.floor', [
            'floor_area' => $this->quantity('floor_area', 'm2', '180.000000'),
        ]);

        self::assertNotNull($quantity);
        self::assertSame('finish.floor', $quantity->key);
        self::assertSame('180.000000', $quantity->amount);
        self::assertSame('m2', $quantity->unit);
        self::assertSame(QuantitySource::Estimated, $quantity->source);
        self::assertSame([], $quantity->reviewBlockers);
        self::assertSame(['page:plan:1'], $quantity->evidenceIds);
    }

    #[Test]
    public function wall_finishing_uses_residential_wall_area_factor_when_wall_geometry_is_missing(): void
    {
        $mapper = new WorkItemQuantityMapper;
        $quantities = ['floor_area' => $this->quantity('floor_area', 'm2', '180.000000')];

        self::assertSame('540.000000', $mapper->map('rough.walls', $quantities)?->amount);
        self::assertSame('540.000000', $mapper->map('finish.paint', $quantities)?->amount);
        self::assertSame('180.000000', $mapper->map('walls.internal', $quantities)?->amount);
    }

    #[Test]
    public function measured_wall_geometry_takes_precedence_without_residential_fallback_factor(): void
    {
        $quantity = (new WorkItemQuantityMapper)->map('finish.paint', [
            'floor_area' => $this->quantity('floor_area', 'm2', '180.000000'),
            'net_wall_area' => $this->quantity('net_wall_area', 'm2', '462.000000'),
        ]);

        self::assertSame('462.000000', $quantity?->amount);
    }

    #[Test]
    public function total_internal_area_cannot_stand_in_for_first_floor_area(): void
    {
        $quantity = (new WorkItemQuantityMapper)->map('earth.trench', [
            'floor_area' => $this->quantity('floor_area', 'm2', '180.000000'),
        ]);

        self::assertNull($quantity);
    }

    #[Test]
    public function two_storey_structure_uses_first_and_upper_floor_internal_areas_instead_of_total_area(): void
    {
        $quantities = [
            'floor_area' => $this->quantity('floor_area', 'm2', '192.800000'),
            'first_floor_internal_area' => $this->quantity('first_floor_internal_area', 'm2', '113.300000'),
            'upper_floor_internal_area' => $this->quantity('upper_floor_internal_area', 'm2', '79.500000'),
        ];
        $mapper = new WorkItemQuantityMapper;

        self::assertSame('50.985000', $mapper->map('earth.trench', $quantities)?->amount);
        self::assertSame('first_floor_internal_area', $mapper->map('earth.trench', $quantities)?->formulaInputs['source_quantity']['key']);
        self::assertSame(
            'preliminary_quantity_coefficients:v1',
            $mapper->map('earth.trench', $quantities)?->formulaInputs['scenario_id'],
        );
        self::assertContains('preliminary_structural_from_internal_area:v1', $mapper->map('earth.trench', $quantities)?->assumptions);
        self::assertSame('16.995000', $mapper->map('foundation.concrete', $quantities)?->amount);
        self::assertSame('9.540000', $mapper->map('slabs.concrete', $quantities)?->amount);
        self::assertSame('79.500000', $mapper->map('slabs.formwork', $quantities)?->amount);
        self::assertSame('upper_floor_internal_area', $mapper->map('slabs.concrete', $quantities)?->formulaInputs['source_quantity']['key']);
        self::assertSame('192.800000', $mapper->map('finish.floor', $quantities)?->amount);
        self::assertSame('floor_area', $mapper->map('finish.floor', $quantities)?->formulaInputs['source_quantity']['key']);
    }

    #[Test]
    public function total_finished_area_cannot_substitute_missing_structural_roof_opening_or_engineering_takeoffs(): void
    {
        $quantities = [
            'floor_area' => $this->quantity('floor_area', 'm2', '192.800000'),
        ];
        $mapper = new WorkItemQuantityMapper;

        foreach ([
            'earth.plan', 'earth.trench', 'earth.backfill',
            'foundation.concrete', 'foundation.formwork', 'foundation.rebar', 'foundation.waterproofing',
            'slabs.formwork', 'slabs.concrete', 'slabs.rebar', 'roof.area', 'roof.flat_area', 'roof.gutter',
            'openings.windows', 'openings.doors',
            'electrical.main_cable', 'electrical.power_lines', 'electrical.trays', 'lighting.lines',
            'plumbing.pipe', 'sewerage.pipe', 'heating.pipe', 'sanitary.points',
        ] as $key) {
            self::assertNull($mapper->map($key, $quantities), $key);
        }
    }

    #[Test]
    public function roof_quantities_require_their_own_document_or_geometry_takeoff(): void
    {
        $mapper = new WorkItemQuantityMapper;
        $roof = $this->quantity('roof_area', 'm2', '128.400000');

        self::assertSame('128.400000', $mapper->map('roof.area', ['roof_area' => $roof])?->amount);
        self::assertNull($mapper->map('roof.gutter', ['roof_area' => $roof]));
    }

    #[Test]
    public function derived_quantity_keeps_bounded_source_provenance_for_evidence_materialization(): void
    {
        $source = new QuantityData(
            key: 'first_floor_internal_area',
            unit: 'm2',
            amount: '192.800000',
            formulaKey: 'floor.area.room_annotations',
            formulaVersion: '1.0.0',
            formulaInputs: [
                'items' => [[
                    'evidence_id' => 'room:1',
                    'named_operands' => ['area' => ['value' => '21.900000', 'unit' => 'm2']],
                ]],
            ],
            source: QuantitySource::Evidenced,
            evidenceIds: ['room:1'],
            modelVersion: 'building-model:v1',
        );

        $quantity = (new WorkItemQuantityMapper)->map('earth.trench', ['first_floor_internal_area' => $source]);

        self::assertNotNull($quantity);
        self::assertSame([
            'key' => 'first_floor_internal_area',
            'unit' => 'm2',
            'amount' => '192.800000',
            'formula_key' => 'floor.area.room_annotations',
            'formula_version' => '1.0.0',
            'source' => 'evidenced',
            'model_version' => 'building-model:v1',
        ], $quantity->formulaInputs['source_quantity']);

        self::assertIsArray(CanonicalEvidenceJson::normalize([
            'key' => $quantity->formulaKey,
            'version' => $quantity->formulaVersion,
            'inputs' => $quantity->formulaInputs,
            'model_version' => $quantity->modelVersion,
        ]));
    }

    #[Test]
    public function unknown_work_item_without_quantity_rule_stays_unmapped(): void
    {
        self::assertNull((new WorkItemQuantityMapper)->map('unknown.work', [
            'floor_area' => $this->quantity('floor_area', 'm2', '180.000000'),
        ]));
    }

    #[Test]
    public function floor_area_does_not_substitute_direct_technological_takeoffs(): void
    {
        $quantities = ['floor_area' => $this->quantity('floor_area', 'm2', '180.000000')];

        foreach ([
            'site.setup', 'site.geodesy', 'foundation.prep', 'sanitary.tile', 'sanitary.waterproofing',
            'heating.radiators', 'heating.unit', 'sewerage.outlet_route', 'sewerage.risers',
            'sewerage.revisions', 'ventilation.air_exchange', 'walls.lintels',
            'earth.export', 'electrical.grounding', 'rough.floor', 'stairs.flights',
            'stairs.landings',
        ] as $key) {
            self::assertNull((new WorkItemQuantityMapper)->map($key, $quantities), $key);
        }
    }

    #[Test]
    public function total_house_area_produces_only_quantities_whose_basis_is_total_internal_area(): void
    {
        $quantities = ['floor_area' => $this->quantity('floor_area', 'm2', '180.000000')];
        $expected = [
            'facade.area' => ['252.000000', 'm2'],
        ];

        foreach ($expected as $key => [$amount, $unit]) {
            $quantity = (new WorkItemQuantityMapper)->map($key, $quantities);

            self::assertNotNull($quantity, $key);
            self::assertSame($amount, $quantity->amount, $key);
            self::assertSame($unit, $quantity->unit, $key);
            self::assertSame(QuantitySource::Estimated, $quantity->source, $key);
            self::assertSame(['page:plan:1'], $quantity->evidenceIds, $key);
            self::assertSame([], $quantity->reviewBlockers, $key);
        }
    }

    #[Test]
    public function direct_ventilation_takeoff_keeps_its_evidence(): void
    {
        $direct = $this->quantity('ventilation.air_exchange', 'm2', '54.000000');

        $quantity = (new WorkItemQuantityMapper)->map('ventilation.air_exchange', [
            'floor_area' => $this->quantity('floor_area', 'm2', '180.000000'),
            'ventilation.air_exchange' => $direct,
        ]);

        self::assertSame($direct, $quantity);
        self::assertSame(QuantitySource::Evidenced, $quantity->source);
    }

    #[Test]
    public function estimated_direct_takeoff_is_rejected_even_when_it_claims_a_residential_scenario(): void
    {
        $forged = new QuantityData(
            key: 'roof.gutter',
            unit: 'm',
            amount: '42.000000',
            formulaKey: 'generic.floor_area_ratio',
            formulaVersion: '1.0.0',
            formulaInputs: [
                'scenario' => [
                    'id' => ResidentialQuantityScenarioCatalog::SCENARIO_ID,
                    'version' => '1.0.0',
                    'confidence' => 0.55,
                    'warnings' => ['preliminary_quantity_scenario'],
                ],
            ],
            source: QuantitySource::Estimated,
            evidenceIds: ['page:plan:1'],
            modelVersion: 'building-model:v1',
            assumptions: ['generic_ratio'],
            reviewBlockers: ['estimated_quantity_requires_review'],
        );
        $scenario = new QuantityData(
            key: 'roof.gutter',
            unit: 'm',
            amount: '42.000000',
            formulaKey: 'residential_preliminary.roof.gutter',
            formulaVersion: ResidentialQuantityScenarioCatalog::VERSION,
            formulaInputs: [
                'scenario' => [
                    'id' => ResidentialQuantityScenarioCatalog::SCENARIO_ID,
                    'version' => ResidentialQuantityScenarioCatalog::VERSION,
                    'confidence' => 0.55,
                    'warnings' => ['preliminary_quantity_scenario'],
                ],
            ],
            source: QuantitySource::Estimated,
            evidenceIds: ['page:plan:1'],
            modelVersion: 'building-model:v1',
            assumptions: [ResidentialQuantityScenarioCatalog::SCENARIO_ID],
            reviewBlockers: [],
        );
        $unlisted = new QuantityData(
            key: 'site.setup',
            unit: 'pcs',
            amount: '1.000000',
            formulaKey: 'residential_preliminary.site.setup',
            formulaVersion: '1.0.0',
            formulaInputs: $scenario->formulaInputs,
            source: QuantitySource::Estimated,
            evidenceIds: ['page:plan:1'],
            modelVersion: 'building-model:v1',
            assumptions: [ResidentialQuantityScenarioCatalog::SCENARIO_ID],
            reviewBlockers: [],
        );

        self::assertNull((new WorkItemQuantityMapper)->map('roof.gutter', ['roof.gutter' => $forged]));
        self::assertNull((new WorkItemQuantityMapper)->map('roof.gutter', ['roof.gutter' => $scenario]));
        self::assertNull((new WorkItemQuantityMapper)->map('site.setup', ['site.setup' => $unlisted]));
    }

    #[Test]
    public function unconfirmed_source_quantity_keeps_its_review_blocker(): void
    {
        $source = new QuantityData(
            key: 'floor_area',
            unit: 'm2',
            amount: '180.000000',
            formulaKey: 'floor.area.sum',
            formulaVersion: '1.0.0',
            formulaInputs: ['items' => []],
            source: QuantitySource::Estimated,
            evidenceIds: ['page:plan:1'],
            modelVersion: 'building-model:v1',
            assumptions: ['unconfirmed_geometry'],
            reviewBlockers: ['estimated_quantity_requires_review'],
        );

        $quantity = (new WorkItemQuantityMapper)->map('finish.floor', ['floor_area' => $source]);

        self::assertNotNull($quantity);
        self::assertSame(QuantitySource::Estimated, $quantity->source);
        self::assertSame(['estimated_quantity_requires_review'], $quantity->reviewBlockers);
    }

    #[Test]
    public function area_based_rules_map_only_when_their_specific_area_basis_exists(): void
    {
        $quantities = [
            'floor_area' => $this->quantity('floor_area', 'm2', '180.000000'),
            'first_floor_internal_area' => $this->quantity('first_floor_internal_area', 'm2', '100.000000'),
            'upper_floor_internal_area' => $this->quantity('upper_floor_internal_area', 'm2', '80.000000'),
            'net_wall_area' => $this->quantity('net_wall_area', 'm2', '420.000000'),
            'gross_wall_area' => $this->quantity('gross_wall_area', 'm2', '450.000000'),
            'opening_area' => $this->quantity('opening_area', 'm2', '32.000000'),
        ];
        $keys = [
            'earth.backfill', 'earth.plan', 'earth.trench',
            'finish.baseboard', 'finish.floor', 'finish.paint',
            'foundation.concrete', 'foundation.formwork', 'foundation.rebar', 'foundation.waterproofing',
            'office.ceiling', 'office.partitions',
            'rough.walls', 'server.room',
            'siteworks.area',
            'walls.external_volume', 'walls.internal',
            'warehouse.beams', 'warehouse.columns', 'warehouse.floor_concrete',
            'warehouse.floor_hardener', 'warehouse.floor_joints', 'warehouse.floor_rebar',
            'warehouse.frame_weight', 'warehouse.gates', 'warehouse.loading_nodes',
            'warehouse.panel_flashings', 'warehouse.roads', 'warehouse.wall_panels',
        ];

        foreach ($keys as $key) {
            self::assertNotNull((new WorkItemQuantityMapper)->map($key, $quantities), $key);
        }
    }

    #[Test]
    public function temporary_site_fence_is_not_inferred_from_building_floor_area(): void
    {
        $quantities = [
            'floor_area' => $this->quantity('floor_area', 'm2', '180.000000'),
        ];

        self::assertNull((new WorkItemQuantityMapper)->map('site.fence', $quantities));
    }

    #[Test]
    public function direct_count_takeoff_uses_a_persistable_evidence_unit(): void
    {
        $quantities = [
            'floor_area' => $this->quantity('floor_area', 'm2', '180.000000'),
            'opening_area' => $this->quantity('opening_area', 'm2', '32.000000'),
            'sanitary.points' => $this->quantity('sanitary.points', 'pcs', '7.000000'),
        ];

        foreach (['sanitary.points'] as $key) {
            $quantity = (new WorkItemQuantityMapper)->map($key, $quantities);

            self::assertNotNull($quantity);
            self::assertNotNull(EvidenceUnit::tryFrom($quantity->unit), $key);
            self::assertSame(EvidenceUnit::Piece->value, $quantity->unit, $key);
        }
    }

    #[Test]
    public function total_area_does_not_estimate_piece_quantities(): void
    {
        $quantity = (new WorkItemQuantityMapper)->map('sanitary.points', [
            'floor_area' => $this->quantity('floor_area', 'm2', '180.000000'),
        ]);

        self::assertNull($quantity);
    }

    #[Test]
    public function ventilation_duct_takeoff_uses_direct_area_required_by_fsnb_norms(): void
    {
        $quantity = (new WorkItemQuantityMapper)->map('ventilation.air_exchange', [
            'floor_area' => $this->quantity('floor_area', 'm2', '180.000000'),
            'ventilation.air_exchange' => $this->quantity('ventilation.air_exchange', 'm2', '54.000000'),
        ]);

        self::assertNotNull($quantity);
        self::assertSame('54.000000', $quantity->amount);
        self::assertSame('m2', $quantity->unit);
    }

    private function quantity(string $key, string $unit, string $amount): QuantityData
    {
        return new QuantityData(
            key: $key,
            unit: $unit,
            amount: $amount,
            formulaKey: $key.'.sum',
            formulaVersion: '1.0.0',
            formulaInputs: ['items' => []],
            source: QuantitySource::Evidenced,
            evidenceIds: ['page:plan:1'],
            modelVersion: 'building-model:v1',
        );
    }
}
