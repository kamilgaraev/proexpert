<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class WorkItemQuantityMapper
{
    public const FORMULA_VERSION = '1.6.0';

    public function isKnownWorkKey(string $workKey): bool
    {
        return $this->rule($workKey) !== null;
    }

    /** Whether the existing rule accepts this purpose without an estimated multiplier. */
    public function acceptsExactSource(string $workKey, string $sourcePurpose, string $sourceUnit): bool
    {
        $rule = $this->rule($workKey);
        if ($rule === null) {
            return false;
        }
        $source = \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\WorkQuantityInputUnit::parse($sourceUnit);
        $target = \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\WorkQuantityInputUnit::parse($rule['unit']);
        if ($source === null || $target === null || $source['unit'] !== $target['unit']) {
            return false;
        }
        if ($sourcePurpose === $workKey) {
            return true;
        }
        if ($sourcePurpose === 'equipment.count' && in_array($workKey, ['equipment.purchase', 'equipment.install'], true)) {
            return true;
        }
        $canonicalFloorGeometry = $workKey === 'rough.floor' && $sourcePurpose === 'floor_area';
        if (DirectTakeoffRequiredWorkItems::contains($workKey) && ! $canonicalFloorGeometry
            && ! in_array($workKey, ['roof.area', 'roof.flat_area'], true)) {
            return false;
        }
        foreach ($rule['sources'] as $candidate) {
            if ($candidate['key'] === $sourcePurpose && BigDecimal::of($candidate['factor'])->isEqualTo(1)) {
                return true;
            }
        }

        return false;
    }

    /** Select an exact canonical formula of this entity when a global alias is unavailable. */
    public function mapForEntity(string $workKey, array $quantities, string $entityId): ?QuantityData
    {
        $matches = [];
        foreach ($quantities as $raw) {
            $quantity = $this->quantity($raw);
            $purpose = $quantity === null ? null : CurrentProjectDerivedQuantityService::formulaPurpose($quantity->formulaKey);
            if ($quantity === null || ! is_string($purpose) || ($quantity->formulaInputs['entity_id'] ?? null) !== $entityId
                || $quantity->reviewBlockers !== [] || ($quantity->source === QuantitySource::Estimated && $quantity->assumptions === [])
                || ($workKey === 'rough.floor' && $quantity->source !== QuantitySource::Evidenced)
                || ($purpose === 'equipment.count' && in_array($workKey, ['equipment.purchase', 'equipment.install'], true)
                    && BigDecimal::of($quantity->amount)->isLessThanOrEqualTo(0))
                || ! $this->acceptsExactSource($workKey, $purpose, $quantity->unit)) {
                continue;
            }
            $identity = $quantity->toArray();
            unset($identity['key']);
            $matches[hash('sha256', \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($identity))] = $quantity;
        }

        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    public function map(string $workItemKey, array $quantities): ?QuantityData
    {
        $direct = $this->quantity($quantities[$workItemKey] ?? null);
        if ($direct !== null) {
            if (DirectTakeoffRequiredWorkItems::contains($workItemKey)
                && $direct->source === QuantitySource::Estimated) {
                return null;
            }

            return $direct;
        }

        $isRoofGeometryAlias = in_array($workItemKey, ['roof.area', 'roof.flat_area'], true);
        if (DirectTakeoffRequiredWorkItems::contains($workItemKey) && ! $isRoofGeometryAlias) {
            return null;
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
            if ($isRoofGeometryAlias
                && ($source->source !== QuantitySource::Evidenced || $source->reviewBlockers !== [])) {
                continue;
            }

            $factor = BigDecimal::of($candidate['factor']);
            if ($factor->isEqualTo(BigDecimal::one())
                && $rule['unit'] === $source->unit
                && $source->source === QuantitySource::Evidenced
                && $source->reviewBlockers === []
                && is_array($source->formulaInputs['operands'] ?? null)
                && $source->formulaInputs['operands'] !== []
                && is_array($source->formulaInputs['snapshot_identity'] ?? null)) {
                return new QuantityData(
                    key: $workItemKey,
                    unit: $source->unit,
                    amount: $source->amount,
                    formulaKey: $source->formulaKey,
                    formulaVersion: $source->formulaVersion,
                    formulaInputs: $source->formulaInputs,
                    source: QuantitySource::Evidenced,
                    evidenceIds: $source->evidenceIds,
                    modelVersion: $source->modelVersion,
                    assumptions: $source->assumptions,
                    reviewBlockers: [],
                );
            }
            $amount = BigDecimal::of($source->amount)->multipliedBy($factor);
            $minimum = BigDecimal::of($rule['minimum'] ?? '0.000001');
            if ($amount->isLessThan($minimum)) {
                $amount = $minimum;
            }
            if ($rule['unit'] === 'pcs') {
                $amount = $amount->toScale(0, RoundingMode::HalfUp);
            }

            $structuralAssumptions = in_array($source->key, ['first_floor_internal_area', 'upper_floor_internal_area'], true)
                ? ['preliminary_structural_from_internal_area:v1']
                : [];

            return new QuantityData(
                key: $workItemKey,
                unit: $rule['unit'],
                amount: (string) $amount->toScale(6, RoundingMode::HalfUp),
                formulaKey: 'work_item.quantity.'.$workItemKey,
                formulaVersion: self::FORMULA_VERSION,
                formulaInputs: [
                    'source_quantity' => $this->sourceReference($source),
                    'factor' => $candidate['factor'],
                    'minimum' => $rule['minimum'] ?? null,
                    'scenario_id' => 'preliminary_quantity_coefficients:v1',
                ],
                source: QuantitySource::Estimated,
                evidenceIds: $source->evidenceIds,
                modelVersion: $source->modelVersion,
                assumptions: array_values(array_unique([
                    ...$source->assumptions,
                    ...$structuralAssumptions,
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

    /** @return array<string, string> */
    private function sourceReference(QuantityData $source): array
    {
        return [
            'key' => $source->key,
            'unit' => $source->unit,
            'amount' => $source->amount,
            'formula_key' => $source->formulaKey,
            'formula_version' => $source->formulaVersion,
            'source' => $source->source->value,
            'model_version' => $source->modelVersion,
        ];
    }

    private function rule(string $key): ?array
    {
        $floorArea = fn (string $factor = '1'): array => ['sources' => [['key' => 'floor_area', 'factor' => $factor]], 'unit' => 'm2'];
        $firstFloorInternalArea = fn (string $factor = '1'): array => [
            'sources' => [['key' => 'first_floor_internal_area', 'factor' => $factor]],
            'unit' => 'm2',
        ];
        $wallArea = fn (string $factor = '1', string $floorAreaFallbackFactor = '1'): array => [
            'sources' => [
                ['key' => 'net_wall_area', 'factor' => $factor],
                ['key' => 'gross_wall_area', 'factor' => $factor],
                ['key' => 'floor_area', 'factor' => $floorAreaFallbackFactor],
            ],
            'unit' => 'm2',
        ];
        $floorLength = fn (string $factor): array => ['sources' => [['key' => 'floor_area', 'factor' => $factor]], 'unit' => 'm'];
        $floorCount = fn (string $factor): array => [
            'sources' => [['key' => 'floor_area', 'factor' => $factor]],
            'unit' => 'pcs',
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
            'unit' => 'pcs',
            'minimum' => '1',
        ];

        return match ($key) {
            'rough.floor', 'finish.floor', 'office.ceiling', 'warehouse.floor_hardener' => $floorArea(),

            'earth.plan', 'siteworks.area', 'warehouse.roads' => $firstFloorInternalArea(),

            'rough.walls', 'finish.paint' => $wallArea('1', '3'),

            'walls.internal', 'office.partitions',
            'warehouse.wall_panels', 'warehouse.panel_flashings' => $wallArea(),

            'facade.area' => [
                'sources' => [
                    ['key' => 'net_wall_area', 'factor' => '1'],
                    ['key' => 'gross_wall_area', 'factor' => '1'],
                    ['key' => 'floor_area', 'factor' => '1.4'],
                ],
                'unit' => 'm2',
            ],

            'earth.trench' => ['sources' => [['key' => 'first_floor_internal_area', 'factor' => '0.45']], 'unit' => 'm3'],
            'earth.backfill' => ['sources' => [['key' => 'first_floor_internal_area', 'factor' => '0.30']], 'unit' => 'm3'],
            'earth.export' => ['sources' => [['key' => 'first_floor_internal_area', 'factor' => '0.15']], 'unit' => 'm3'],
            'foundation.concrete' => [
                'sources' => [
                    ['key' => 'foundation_volume', 'factor' => '1'],
                ],
                'unit' => 'm3',
            ],
            'foundation.formwork', 'foundation.waterproofing' => $firstFloorInternalArea('0.75'),
            'foundation.rebar' => [
                'sources' => [
                    ['key' => 'foundation_volume', 'factor' => '120'],
                    ['key' => 'first_floor_internal_area', 'factor' => '18'],
                ],
                'unit' => 'kg',
            ],
            'walls.external_volume' => [
                'sources' => [
                    ['key' => 'net_wall_volume', 'factor' => '1'],
                ],
                'unit' => 'm3',
            ],
            'warehouse.floor_concrete' => ['sources' => [['key' => 'floor_area', 'factor' => '0.12']], 'unit' => 'm3'],
            'warehouse.floor_rebar' => ['sources' => [['key' => 'floor_area', 'factor' => '9']], 'unit' => 'kg'],
            'slabs.concrete' => ['sources' => [['key' => 'upper_floor_internal_area', 'factor' => '0.12']], 'unit' => 'm3'],
            'slabs.rebar' => ['sources' => [['key' => 'upper_floor_internal_area', 'factor' => '12']], 'unit' => 'kg'],
            'slabs.formwork' => ['sources' => [['key' => 'upper_floor_internal_area', 'factor' => '1']], 'unit' => 'm2'],
            'warehouse.frame_weight' => ['sources' => [['key' => 'floor_area', 'factor' => '35']], 'unit' => 'kg'],
            'warehouse.columns', 'warehouse.beams' => ['sources' => [['key' => 'floor_area', 'factor' => '18']], 'unit' => 'kg'],

            'roof.area', 'roof.flat_area' => [
                'sources' => [
                    ['key' => 'roof_area', 'factor' => '1'],
                ],
                'unit' => 'm2',
            ],
            'finish.baseboard' => $floorLength('0.80'),
            'warehouse.floor_joints' => $floorLength('0.25'),
            'stairs.railings' => $floorLength('0.08'),
            'stairs.flights' => $floorArea('0.05'),
            'stairs.landings' => $floorArea('0.015'),
            'networks.external' => $floorLength('0.20'),

            'openings.windows' => [
                'sources' => [
                    ['key' => 'opening_area', 'factor' => '0.55'],
                    ['key' => 'floor_area', 'factor' => '0.08'],
                ],
                'unit' => 'm2',
            ],
            'openings.doors' => [
                'sources' => [
                    ['key' => 'opening_area', 'factor' => '0.45'],
                    ['key' => 'floor_area', 'factor' => '0.12'],
                ],
                'unit' => 'm2',
            ],
            'walls.lintels' => $floorCount('0.12'),
            'warehouse.gates', 'warehouse.loading_nodes' => $floorCount('0.005'),

            'electrical.main_cable' => $engineeringLength('electrical', '0.40'),
            'equipment.purchase', 'equipment.install' => ['sources' => [['key' => 'equipment.count', 'factor' => '1']], 'unit' => 'pcs'],
            'pipelines.install', 'ventilation.duct' => ['sources' => [['key' => $key, 'factor' => '1']], 'unit' => 'm'],
            'electrical.trays' => $engineeringLength('electrical', '0.25'),
            'electrical.power_lines', 'lighting.lines' => $engineeringLength('electrical', '0.80'),
            'electrical.grounding' => $engineeringLength('electrical', '0.12'),
            'warehouse.low_current' => $engineeringLength('electrical', '0.35'),
            'plumbing.pipe' => $engineeringLength('water', '0.35'),
            'sewerage.pipe' => $engineeringLength('sewer', '0.25'),
            'heating.pipe' => $engineeringLength('heating', '0.50'),
            'ventilation.air_exchange' => [
                'sources' => [
                    ['key' => 'engineering.ventilation.area', 'factor' => '1'],
                ],
                'unit' => 'm2',
            ],

            'warehouse.lighting' => $engineeringCount('electrical', '0.08'),
            'office.network_points', 'warehouse.fire' => $engineeringCount('electrical', '0.06'),
            'sanitary.points' => $engineeringCount('water', '0.04'),
            'sewerage.outlet_route' => $engineeringLength('sewer', '0.04'),
            'sewerage.revisions', 'sewerage.risers' => $engineeringCount('sewer', '0.015'),
            'heating.radiators' => $engineeringCount('heating', '0.06'),
            'heating.air_curtains' => $engineeringCount('heating', '0.01'),
            'ventilation.office_points', 'ventilation.warehouse_points' => $engineeringCount('ventilation', '0.05'),

            'heating.unit', 'server.room' => [
                'sources' => [['key' => 'floor_area', 'factor' => '0.001']],
                'unit' => 'pcs',
                'minimum' => '1',
            ],
            default => null,
        };
    }
}
