<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class WorkItemQuantityMapper
{
    public const FORMULA_VERSION = '1.0.0';

    public function map(string $workItemKey, array $quantities): ?QuantityData
    {
        $direct = $this->quantity($quantities[$workItemKey] ?? null);
        if ($direct !== null) {
            return $direct;
        }

        $rule = $this->rule($workItemKey);
        if ($rule === null) {
            return null;
        }

        foreach ($rule['sources'] as $candidate) {
            $source = $this->quantity($quantities[$candidate['key']] ?? null);
            if ($source === null || BigDecimal::of($source->amount)->isLessThanOrEqualTo(BigDecimal::zero())) {
                continue;
            }

            $factor = BigDecimal::of($candidate['factor']);
            $amount = BigDecimal::of($source->amount)->multipliedBy($factor);
            $minimum = BigDecimal::of($rule['minimum'] ?? '0.000001');
            if ($amount->isLessThan($minimum)) {
                $amount = $minimum;
            }

            return new QuantityData(
                key: $workItemKey,
                unit: $rule['unit'],
                amount: (string) $amount->toScale(6, RoundingMode::HalfUp),
                formulaKey: 'work_item.quantity.'.$workItemKey,
                formulaVersion: self::FORMULA_VERSION,
                formulaInputs: [
                    'source_quantity' => $source->toArray(),
                    'factor' => $candidate['factor'],
                    'minimum' => $rule['minimum'] ?? null,
                ],
                source: $source->source,
                evidenceIds: $source->evidenceIds,
                modelVersion: $source->modelVersion,
                assumptions: array_values(array_unique([
                    ...$source->assumptions,
                    'preliminary_work_quantity:'.$workItemKey,
                ])),
                reviewBlockers: $source->reviewBlockers,
            );
        }

        return null;
    }

    private function quantity(mixed $quantity): ?QuantityData
    {
        if ($quantity instanceof QuantityData) {
            return $quantity;
        }

        return is_array($quantity) ? QuantityData::fromArray($quantity) : null;
    }

    private function rule(string $key): ?array
    {
        $floorArea = fn (string $factor = '1'): array => ['sources' => [['key' => 'floor_area', 'factor' => $factor]], 'unit' => 'm2'];
        $wallArea = fn (string $factor = '1'): array => [
            'sources' => [
                ['key' => 'net_wall_area', 'factor' => $factor],
                ['key' => 'gross_wall_area', 'factor' => $factor],
                ['key' => 'floor_area', 'factor' => $factor],
            ],
            'unit' => 'm2',
        ];
        $floorLength = fn (string $factor): array => ['sources' => [['key' => 'floor_area', 'factor' => $factor]], 'unit' => 'm'];
        $floorCount = fn (string $factor): array => [
            'sources' => [['key' => 'floor_area', 'factor' => $factor]],
            'unit' => 'шт',
            'minimum' => '1',
        ];
        $engineeringLength = fn (string $system, string $factor): array => [
            'sources' => [
                ['key' => 'engineering.'.$system.'.length', 'factor' => '1'],
                ['key' => 'floor_area', 'factor' => $factor],
            ],
            'unit' => 'm',
        ];
        $engineeringCount = fn (string $system, string $factor): array => [
            'sources' => [
                ['key' => 'engineering.'.$system.'.point', 'factor' => '1'],
                ['key' => 'floor_area', 'factor' => $factor],
            ],
            'unit' => 'шт',
            'minimum' => '1',
        ];

        return match ($key) {
            'rough.floor', 'finish.floor', 'office.ceiling', 'warehouse.floor_hardener',
            'earth.plan', 'site.setup', 'site.geodesy', 'siteworks.area', 'warehouse.roads',
            'sanitary.tile' => $floorArea(),

            'rough.walls', 'finish.paint', 'facade.area', 'walls.internal',
            'office.partitions', 'warehouse.wall_panels', 'warehouse.panel_flashings' => $wallArea(),

            'earth.trench' => ['sources' => [['key' => 'floor_area', 'factor' => '0.45']], 'unit' => 'm3'],
            'earth.backfill' => ['sources' => [['key' => 'floor_area', 'factor' => '0.30']], 'unit' => 'm3'],
            'earth.export' => ['sources' => [['key' => 'floor_area', 'factor' => '0.15']], 'unit' => 'm3'],
            'foundation.concrete' => [
                'sources' => [
                    ['key' => 'foundation_volume', 'factor' => '1'],
                    ['key' => 'floor_area', 'factor' => '0.15'],
                ],
                'unit' => 'm3',
            ],
            'foundation.prep' => $floorArea('0.45'),
            'foundation.formwork', 'foundation.waterproofing' => $floorArea('0.75'),
            'foundation.rebar' => [
                'sources' => [
                    ['key' => 'foundation_volume', 'factor' => '120'],
                    ['key' => 'floor_area', 'factor' => '18'],
                ],
                'unit' => 'kg',
            ],
            'walls.external_volume' => [
                'sources' => [
                    ['key' => 'gross_wall_area', 'factor' => '0.30'],
                    ['key' => 'floor_area', 'factor' => '0.45'],
                ],
                'unit' => 'm3',
            ],
            'warehouse.floor_concrete' => ['sources' => [['key' => 'floor_area', 'factor' => '0.12']], 'unit' => 'm3'],
            'warehouse.floor_rebar' => ['sources' => [['key' => 'floor_area', 'factor' => '9']], 'unit' => 'kg'],
            'warehouse.frame_weight' => ['sources' => [['key' => 'floor_area', 'factor' => '35']], 'unit' => 'kg'],
            'warehouse.columns', 'warehouse.beams' => ['sources' => [['key' => 'floor_area', 'factor' => '18']], 'unit' => 'kg'],

            'roof.area', 'roof.flat_area' => [
                'sources' => [
                    ['key' => 'roof_area', 'factor' => '1'],
                    ['key' => 'floor_area', 'factor' => '0.55'],
                ],
                'unit' => 'm2',
            ],
            'roof.gutter' => $floorLength('0.15'),
            'finish.baseboard' => $floorLength('0.40'),
            'site.fence' => $floorLength('0.35'),
            'warehouse.floor_joints' => $floorLength('0.25'),
            'stairs.railings' => $floorLength('0.08'),
            'networks.external' => $floorLength('0.20'),

            'openings.windows', 'openings.doors', 'walls.lintels' => [
                'sources' => [
                    ['key' => 'opening_area', 'factor' => '0.5'],
                    ['key' => 'floor_area', 'factor' => '0.04'],
                ],
                'unit' => 'шт',
                'minimum' => '1',
            ],
            'stairs.flights', 'stairs.landings' => $floorCount('0.01'),
            'warehouse.gates', 'warehouse.loading_nodes' => $floorCount('0.005'),

            'electrical.main_cable' => $engineeringLength('electrical', '0.40'),
            'electrical.trays' => $engineeringLength('electrical', '0.25'),
            'electrical.power_lines', 'lighting.lines' => $engineeringLength('electrical', '0.80'),
            'electrical.grounding' => $engineeringLength('electrical', '0.12'),
            'warehouse.low_current' => $engineeringLength('electrical', '0.35'),
            'plumbing.pipe' => $engineeringLength('water', '0.35'),
            'sewerage.pipe' => $engineeringLength('sewer', '0.25'),
            'heating.pipe' => $engineeringLength('heating', '0.50'),
            'ventilation.air_exchange' => $engineeringLength('ventilation', '0.30'),

            'warehouse.lighting' => $engineeringCount('electrical', '0.08'),
            'office.network_points', 'warehouse.fire' => $engineeringCount('electrical', '0.06'),
            'sanitary.points' => $engineeringCount('water', '0.04'),
            'sewerage.outlets' => $engineeringCount('sewer', '0.04'),
            'sewerage.revisions', 'sewerage.risers' => $engineeringCount('sewer', '0.015'),
            'heating.radiators' => $engineeringCount('heating', '0.06'),
            'heating.air_curtains' => $engineeringCount('heating', '0.01'),
            'ventilation.office_points', 'ventilation.warehouse_points' => $engineeringCount('ventilation', '0.05'),

            'heating.unit', 'server.room' => [
                'sources' => [['key' => 'floor_area', 'factor' => '0.001']],
                'unit' => 'компл',
                'minimum' => '1',
            ],
            default => null,
        };
    }
}
