<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceUnit;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkItemQuantityMapperTest extends TestCase
{
    #[Test]
    public function floor_finishing_uses_confirmed_floor_area_as_estimation_basis(): void
    {
        $quantity = (new WorkItemQuantityMapper)->map('rough.floor', [
            'floor_area' => $this->quantity('floor_area', 'm2', '180.000000'),
        ]);

        self::assertNotNull($quantity);
        self::assertSame('rough.floor', $quantity->key);
        self::assertSame('180.000000', $quantity->amount);
        self::assertSame('m2', $quantity->unit);
        self::assertSame(QuantitySource::Estimated, $quantity->source);
        self::assertSame([], $quantity->reviewBlockers);
        self::assertSame(['page:plan:1'], $quantity->evidenceIds);
    }

    #[Test]
    public function earthworks_volume_is_derived_from_evidenced_floor_area(): void
    {
        $quantity = (new WorkItemQuantityMapper)->map('earth.trench', [
            'floor_area' => $this->quantity('floor_area', 'm2', '180.000000'),
        ]);

        self::assertNotNull($quantity);
        self::assertSame('earth.trench', $quantity->key);
        self::assertSame('81.000000', $quantity->amount);
        self::assertSame('m3', $quantity->unit);
        self::assertSame('work_item.quantity.earth.trench', $quantity->formulaKey);
        self::assertSame('0.45', $quantity->formulaInputs['factor']);
        self::assertSame('floor_area', $quantity->formulaInputs['source_quantity']['key']);
        self::assertSame([], $quantity->reviewBlockers);
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
            'site.setup', 'site.geodesy', 'foundation.prep', 'sanitary.tile',
            'facade.area', 'heating.radiators', 'heating.unit', 'openings.doors', 'openings.windows',
            'stairs.flights', 'stairs.landings', 'stairs.railings', 'ventilation.air_exchange', 'walls.lintels',
        ] as $key) {
            self::assertNull((new WorkItemQuantityMapper)->map($key, $quantities), $key);
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
    public function all_catalog_work_items_have_a_model_quantity_rule(): void
    {
        $quantities = [
            'floor_area' => $this->quantity('floor_area', 'm2', '180.000000'),
            'net_wall_area' => $this->quantity('net_wall_area', 'm2', '420.000000'),
            'gross_wall_area' => $this->quantity('gross_wall_area', 'm2', '450.000000'),
            'opening_area' => $this->quantity('opening_area', 'm2', '32.000000'),
        ];
        $keys = [
            'earth.backfill', 'earth.export', 'earth.plan', 'earth.trench',
            'electrical.grounding', 'electrical.main_cable', 'electrical.power_lines', 'electrical.trays',
            'finish.baseboard', 'finish.floor', 'finish.paint',
            'foundation.concrete', 'foundation.formwork', 'foundation.rebar', 'foundation.waterproofing',
            'heating.air_curtains', 'heating.pipe', 'lighting.lines',
            'networks.external', 'office.ceiling', 'office.network_points', 'office.partitions',
            'plumbing.pipe', 'roof.area', 'roof.flat_area', 'roof.gutter',
            'rough.floor', 'rough.walls', 'sanitary.points', 'server.room',
            'sewerage.outlets', 'sewerage.pipe', 'sewerage.revisions', 'sewerage.risers',
            'site.fence', 'siteworks.area',
            'ventilation.office_points', 'ventilation.warehouse_points',
            'walls.external_volume', 'walls.internal',
            'warehouse.beams', 'warehouse.columns', 'warehouse.fire', 'warehouse.floor_concrete',
            'warehouse.floor_hardener', 'warehouse.floor_joints', 'warehouse.floor_rebar',
            'warehouse.frame_weight', 'warehouse.gates', 'warehouse.lighting', 'warehouse.loading_nodes',
            'warehouse.low_current', 'warehouse.panel_flashings', 'warehouse.roads', 'warehouse.wall_panels',
        ];

        foreach ($keys as $key) {
            self::assertNotNull((new WorkItemQuantityMapper)->map($key, $quantities), $key);
        }
    }

    #[Test]
    public function every_mapped_count_uses_a_persistable_evidence_unit(): void
    {
        $quantities = [
            'floor_area' => $this->quantity('floor_area', 'm2', '180.000000'),
            'opening_area' => $this->quantity('opening_area', 'm2', '32.000000'),
        ];

        foreach (['sanitary.points', 'sewerage.outlets'] as $key) {
            $quantity = (new WorkItemQuantityMapper)->map($key, $quantities);

            self::assertNotNull($quantity);
            self::assertNotNull(EvidenceUnit::tryFrom($quantity->unit), $key);
            self::assertSame(EvidenceUnit::Piece->value, $quantity->unit, $key);
        }
    }

    #[Test]
    public function estimated_piece_quantities_are_whole_items(): void
    {
        $quantity = (new WorkItemQuantityMapper)->map('sanitary.points', [
            'floor_area' => $this->quantity('floor_area', 'm2', '180.000000'),
        ]);

        self::assertNotNull($quantity);
        self::assertSame('7.000000', $quantity->amount);
        self::assertSame('pcs', $quantity->unit);
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
