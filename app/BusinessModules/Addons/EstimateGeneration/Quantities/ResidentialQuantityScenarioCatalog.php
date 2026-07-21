<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\RoomData;
use App\BusinessModules\Addons\EstimateGeneration\Services\ObjectTypeSignalClassifier;
use App\BusinessModules\Addons\EstimateGeneration\Services\RoofTypeResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class ResidentialQuantityScenarioCatalog
{
    public const VERSION = '3.4.0';

    public const SCENARIO_ID = 'residential_preliminary_scenario:v17';

    private const UNITS = [
        'electrical.grounding' => 'm',
        'electrical.main_cable' => 'm',
        'electrical.outlets' => 'pcs',
        'electrical.panel' => 'pcs',
        'electrical.power_lines' => 'm',
        'electrical.switches' => 'pcs',
        'foundation.prep' => 'm3',
        'finish.floor' => 'm2',
        'finish.paint' => 'm2',
        'heating.pipe' => 'm',
        'heating.radiators' => 'pcs',
        'heating.unit' => 'pcs',
        'lighting.lines' => 'm',
        'lighting.fixtures' => 'pcs',
        'openings.doors' => 'm2',
        'openings.windows' => 'm2',
        'plumbing.pipe' => 'm',
        'roof.area' => 'm2',
        'roof.battens' => 'm2',
        'roof.flat_area' => 'm2',
        'roof.gutter' => 'm',
        'roof.membrane' => 'm2',
        'roof.rafters' => 'm3',
        'roof.vapor_barrier' => 'm2',
        'rough.floor' => 'm2',
        'rough.ceiling' => 'm2',
        'sanitary.showers' => 'pcs',
        'sanitary.floor_tile' => 'm2',
        'sanitary.toilets' => 'pcs',
        'sanitary.washbasins' => 'pcs',
        'sanitary.tile' => 'm2',
        'sanitary.waterproofing' => 'm2',
        'sewerage.pipe' => 'm',
        'sewerage.outlet_route' => 'm',
        'stairs.flights' => 'm2',
        'stairs.landings' => 'm2',
        'stairs.railings' => 'm',
        'sewerage.revisions' => 'pcs',
        'sewerage.risers' => 'pcs',
        'walls.lintels' => 'pcs',
        'finish.ceiling' => 'm2',
        'ventilation.air_exchange' => 'm2',
        'ventilation.distribution_devices' => 'pcs',
    ];

    public function __construct(private readonly RoofTypeResolver $roofTypes = new RoofTypeResolver) {}

    public function build(array $baseQuantities, NormalizedBuildingModelData $model, array $analysis): ResidentialQuantityScenarioResult
    {
        if (! $this->supports($analysis) || $model->scaleStatus !== 'confirmed' || $model->evidenceIds === []) {
            return new ResidentialQuantityScenarioResult([], []);
        }

        $floorArea = $this->scenarioBasis($this->quantity($baseQuantities['floor_area'] ?? null), $model);
        $wallArea = $this->scenarioBasis($this->quantity($baseQuantities['net_wall_area'] ?? null), $model)
            ?? $this->scenarioBasis($this->quantity($baseQuantities['gross_wall_area'] ?? null), $model);
        $areaBasisAssumptions = $floorArea !== null
            && in_array('preliminary_total_area_with_confirmed_geometry', $floorArea->assumptions, true)
            ? ['preliminary_total_area_with_confirmed_geometry']
            : [];
        $ceilingArea = $this->scenarioBasis($this->quantity($baseQuantities['ceiling_area'] ?? null), $model);
        $firstFloorArea = $this->scenarioBasis($this->quantity($baseQuantities['first_floor_internal_area'] ?? null), $model);
        $floors = $model->floors;
        $floorCount = count($floors);
        if ($firstFloorArea === null && $floorArea !== null && $floorCount > 0) {
            $firstFloorArea = $this->scaled(
                'first_floor_internal_area',
                'm2',
                $floorArea,
                (string) BigDecimal::one()->dividedBy($floorCount, 8, RoundingMode::HalfUp),
                ['equal_floor_area_distribution_by_documented_floor_count'],
            );
        }
        $rooms = $floors === []
            ? []
            : array_merge(...array_map(static fn ($floor): array => $floor->rooms, $floors));
        $roomCount = count($rooms);
        $serviceRooms = array_values(array_filter($rooms, fn (RoomData $room): bool => $this->isServiceRoom($room)));
        $finishedWetRooms = array_values(array_filter($rooms, fn (RoomData $room): bool => $this->isFinishedWetRoom($room)));
        $quantities = [];
        $omissions = [];
        $roofType = $this->roofTypes->resolve($analysis);

        if ($firstFloorArea !== null && $firstFloorArea->evidenceIds !== []) {
            $quantities['foundation.prep'] = $this->scaled(
                'foundation.prep',
                'm3',
                $firstFloorArea,
                '0.10',
                ['residential_foundation_preparation_thickness_m:0.10'],
            );
            $omissions[] = $this->omission('earth.export', 'soil_transport_inputs_missing');
        } else {
            $omissions[] = $this->omission('foundation.prep', 'foundation_footprint_missing');
            $omissions[] = $this->omission('earth.export', 'soil_transport_inputs_missing');
        }

        if ($firstFloorArea !== null && $firstFloorArea->evidenceIds !== [] && in_array($roofType, ['pitched', 'flat'], true)) {
            if ($roofType === 'pitched') {
                $roofArea = $this->scaled(
                    'roof.area',
                    'm2',
                    $firstFloorArea,
                    '1.35',
                    ['pitched_roof_area_factor:1.35'],
                );
                $quantities['roof.area'] = $roofArea;
                foreach (['roof.rafters', 'roof.vapor_barrier', 'roof.membrane', 'roof.battens', 'roof.gutter'] as $roofPart) {
                    $omissions[] = $this->omission($roofPart, 'roof_composition_evidence_missing');
                }
            } else {
                $quantities['roof.flat_area'] = $this->scaled(
                    'roof.flat_area',
                    'm2',
                    $firstFloorArea,
                    '1.00',
                    ['flat_roof_projection_factor:1.00'],
                );
                $omissions[] = $this->omission('roof.gutter', 'external_gutter_not_inferred_for_flat_roof');
            }
            $quantities['electrical.grounding'] = $this->equivalentPerimeter(
                'electrical.grounding',
                $firstFloorArea,
                '1.00',
                ['equivalent_rectangular_footprint'],
            );
        } else {
            $roofQuantityKey = $roofType === 'flat' ? 'roof.flat_area' : 'roof.area';
            $omissions[] = $this->omission($roofQuantityKey, 'roof_geometry_takeoff_missing');
            $omissions[] = $this->omission('roof.gutter', 'roof_drainage_takeoff_missing');
            $omissions[] = $this->omission('roof.rafters', 'roof_structure_geometry_missing');
            $omissions[] = $this->omission('electrical.grounding', 'grounding_installation_type_missing');
        }

        if ($floorArea !== null && $floorArea->evidenceIds !== []) {
            $quantities['rough.floor'] = $this->scaled(
                'rough.floor',
                'm2',
                $floorArea,
                '1.00',
                ['residential_rough_floor_area'],
            );
            foreach ([
                'openings.windows' => ['m2', '0.12', 'residential_window_area_ratio:0.12'],
                'electrical.main_cable' => ['m', '0.40', 'residential_main_cable_length_ratio:0.40'],
                'electrical.power_lines' => ['m', '0.80', 'residential_power_line_length_ratio:0.80'],
                'lighting.lines' => ['m', '0.80', 'residential_lighting_line_length_ratio:0.80'],
                'plumbing.pipe' => ['m', '0.35', 'residential_water_pipe_length_ratio:0.35'],
                'sewerage.pipe' => ['m', '0.25', 'residential_sewer_pipe_length_ratio:0.25'],
                'heating.pipe' => ['m', '0.50', 'residential_heating_pipe_length_ratio:0.50'],
                'ventilation.air_exchange' => ['m2', '0.12', 'residential_exhaust_duct_surface_ratio:0.12'],
            ] as $key => [$unit, $factor, $assumption]) {
                $quantities[$key] = $this->scaled($key, $unit, $floorArea, $factor, [$assumption]);
            }
            $omissions[] = $this->omission('heating.unit', 'heating_source_type_missing');
            $quantities['heating.radiators'] = $this->radiatorDeviceQuantity($floorArea, $areaBasisAssumptions);
            $quantities['electrical.panel'] = $this->countBased(
                'electrical.panel', 'pcs', 1, '1', $model, 'residential_house_count',
                ['one_preliminary_distribution_panel_per_house', ...$areaBasisAssumptions],
            );
            $quantities['electrical.outlets'] = $this->countBased(
                'electrical.outlets', 'pcs', max(1, max($roomCount * 4, (int) ceil((float) $floorArea->amount / 8))),
                '1', $model, 'room_and_floor_area_allowance', ['preliminary_outlet_count_by_rooms_and_area', ...$areaBasisAssumptions],
            );
            $quantities['electrical.switches'] = $this->countBased(
                'electrical.switches', 'pcs', max(1, max($roomCount, (int) ceil((float) $floorArea->amount / 15))),
                '1', $model, 'room_and_floor_area_allowance', ['preliminary_switch_count_by_rooms_and_area', ...$areaBasisAssumptions],
            );
            $quantities['lighting.fixtures'] = $this->countBased(
                'lighting.fixtures', 'pcs', max(1, max($roomCount, (int) ceil((float) $floorArea->amount / 10))),
                '1', $model, 'room_and_floor_area_allowance', ['preliminary_luminaire_count_by_rooms_and_area', ...$areaBasisAssumptions],
            );
            if ($finishedWetRooms !== []) {
                $quantities['ventilation.distribution_devices'] = $this->countBased(
                    'ventilation.distribution_devices', 'pcs', count($finishedWetRooms),
                    '1', $model, 'documented_wet_room_count',
                    ['one_preliminary_exhaust_terminal_per_documented_wet_room', ...$areaBasisAssumptions],
                );
            } else {
                $omissions[] = $this->omission(
                    'ventilation.distribution_devices',
                    'documented_wet_rooms_missing',
                );
            }
        } else {
            foreach ([
                'openings.windows', 'electrical.main_cable', 'electrical.power_lines', 'lighting.lines',
                'electrical.panel', 'electrical.outlets', 'electrical.switches', 'lighting.fixtures',
                'plumbing.pipe', 'sewerage.pipe', 'heating.pipe', 'heating.unit',
                'ventilation.air_exchange',
            ] as $key) {
                $omissions[] = $this->omission($key, 'total_internal_area_missing');
            }
        }

        $ceilingBasis = $ceilingArea ?? $floorArea;
        if ($ceilingBasis !== null && $ceilingBasis->evidenceIds !== []) {
            $quantities['rough.ceiling'] = $this->scaled(
                'rough.ceiling',
                'm2',
                $ceilingBasis,
                '1.00',
                [
                    'residential_internal_ceiling_preparation_area',
                    ...($ceilingArea === null ? ['ceiling_area_equal_floor_area_preliminary'] : []),
                ],
            );
            $quantities['finish.ceiling'] = $this->scaled(
                'finish.ceiling',
                'm2',
                $ceilingBasis,
                '1.00',
                [
                    'residential_internal_ceiling_finish_area',
                    ...($ceilingArea === null ? ['ceiling_area_equal_floor_area_preliminary'] : []),
                ],
            );
        }

        if ($roomCount > 0) {
            $quantities['openings.doors'] = $this->countBased(
                'openings.doors',
                'm2',
                $roomCount,
                '1.8',
                $model,
                'annotated_room_count',
                ['one_door_leaf_area_per_annotated_room_m2:1.8'],
            );
            $quantities['walls.lintels'] = $this->countBased(
                'walls.lintels',
                'pcs',
                max(1, (int) ceil($roomCount * 0.8)),
                '1',
                $model,
                'annotated_room_count',
                ['preliminary_lintel_count_by_room_count_factor:0.8'],
            );
        } else {
            $omissions[] = $this->omission('openings.doors', 'room_annotations_missing');
        }

        if ($floorCount > 1) {
            $transitions = $floorCount - 1;
            $quantities['stairs.flights'] = $this->countBased(
                'stairs.flights',
                'm2',
                $transitions,
                '8',
                $model,
                'documented_interfloor_transition_count',
                ['residential_stair_horizontal_projection_per_transition_m2:8'],
            );
            $quantities['stairs.landings'] = $this->countBased(
                'stairs.landings',
                'm2',
                $transitions,
                '4',
                $model,
                'documented_interfloor_transition_count',
                ['residential_stair_landing_area_per_transition_m2:4'],
            );
            $quantities['stairs.railings'] = $this->countBased(
                'stairs.railings',
                'm',
                $transitions,
                '8',
                $model,
                'documented_interfloor_transition_count',
                ['residential_stair_railing_length_per_transition_m:8'],
            );
        } else {
            foreach (['stairs.flights', 'stairs.landings', 'stairs.railings'] as $key) {
                $omissions[] = $this->omission($key, $floorCount === 1 ? 'single_storey_house' : 'floor_count_missing');
            }
        }

        $wetRoomCount = count($finishedWetRooms);
        if ($wetRoomCount > 0) {
            if ($floorCount > 0) {
                foreach (['sewerage.risers', 'sewerage.revisions'] as $sewerageKey) {
                    $quantities[$sewerageKey] = $this->countBased(
                        $sewerageKey,
                        'pcs',
                        $floorCount,
                        '1',
                        $model,
                        'documented_floor_count',
                        ['one_preliminary_'.str_replace('.', '_', $sewerageKey).'_per_documented_floor'],
                    );
                }
            } else {
                $omissions[] = $this->omission('sewerage.risers', 'floor_count_missing');
                $omissions[] = $this->omission('sewerage.revisions', 'floor_count_missing');
            }
            $bathingRoomCount = count(array_filter(
                $finishedWetRooms,
                fn (RoomData $room): bool => $this->isBathOrShowerRoom($room),
            ));
            $quantities['sanitary.showers'] = $this->countBased(
                'sanitary.showers',
                'pcs',
                max(1, $bathingRoomCount),
                '1',
                $model,
                'documented_bathing_room_count_or_house_minimum',
                ['one_preliminary_shower_for_each_documented_bathing_room_house_minimum:1'],
            );
            foreach (['sanitary.toilets', 'sanitary.washbasins'] as $fixtureKey) {
                $quantities[$fixtureKey] = $this->countBased(
                    $fixtureKey,
                    'pcs',
                    $wetRoomCount,
                    '1',
                    $model,
                    'documented_wet_room_count',
                    ['one_preliminary_'.str_replace('.', '_', $fixtureKey).'_per_wet_room'],
                );
            }
        } else {
            $omissions[] = $this->omission('sewerage.risers', 'documented_wet_rooms_missing');
            $omissions[] = $this->omission('sewerage.revisions', 'documented_wet_rooms_missing');
            foreach (['sanitary.showers', 'sanitary.toilets', 'sanitary.washbasins'] as $fixtureKey) {
                $omissions[] = $this->omission($fixtureKey, 'documented_wet_rooms_missing');
            }
        }
        $omissions[] = $this->omission('sewerage.outlet_route', 'sewer_outlet_route_missing');

        $finishedWetRoomAreas = array_values(array_filter(
            array_map(fn (RoomData $room): ?array => $this->roomArea($room), $finishedWetRooms),
            static fn (?array $room): bool => $room !== null && $room['evidence_ids'] !== [],
        ));
        if ($finishedWetRoomAreas !== []) {
            $wetFloorArea = array_reduce(
                $finishedWetRoomAreas,
                static fn (BigDecimal $sum, array $room): BigDecimal => $sum->plus($room['area']),
                BigDecimal::zero(),
            );
            $wetRoomEvidenceIds = array_values(array_unique(array_merge(...array_column($finishedWetRoomAreas, 'evidence_ids'))));
            $wetRoomInputs = array_map(static fn (array $room): array => [
                'room_key' => $room['room_key'],
                'area_m2' => (string) $room['area'],
            ], $finishedWetRoomAreas);
            $quantities['sanitary.waterproofing'] = $this->make(
                'sanitary.waterproofing',
                'm2',
                (string) $wetFloorArea->multipliedBy('1.10')->toScale(6, RoundingMode::HalfUp),
                ['documented_wet_rooms' => $wetRoomInputs, 'floor_and_upturn_factor' => '1.10'],
                $wetRoomEvidenceIds,
                $model->modelVersion,
                ['wet_zone_floor_area_with_perimeter_upturn_factor:1.10'],
            );
            $quantities['sanitary.tile'] = $this->make(
                'sanitary.tile',
                'm2',
                (string) $wetFloorArea->multipliedBy('3.35')->toScale(6, RoundingMode::HalfUp),
                ['documented_wet_rooms' => $wetRoomInputs, 'preliminary_wall_finish_factor' => '3.35'],
                $wetRoomEvidenceIds,
                $model->modelVersion,
                ['preliminary_wet_room_wall_tile_factor:3.35'],
            );
            $quantities['sanitary.floor_tile'] = $this->make(
                'sanitary.floor_tile',
                'm2',
                (string) $wetFloorArea->toScale(6, RoundingMode::HalfUp),
                ['documented_wet_rooms' => $wetRoomInputs],
                $wetRoomEvidenceIds,
                $model->modelVersion,
                ['wet_zone_floor_tile_uses_evidenced_room_area'],
            );
        } else {
            $omissions[] = $this->omission('sanitary.waterproofing', 'finished_wet_room_area_missing');
            $omissions[] = $this->omission('sanitary.tile', 'finished_wet_room_area_missing');
            $omissions[] = $this->omission('sanitary.floor_tile', 'finished_wet_room_area_missing');
        }

        if ($floorArea !== null && $floorArea->evidenceIds !== []) {
            $quantities['finish.floor'] = $this->subtract(
                'finish.floor',
                $floorArea,
                $quantities['sanitary.floor_tile'] ?? null,
                ['dry_floor_area_excludes_evidenced_wet_rooms'],
            );
        }

        $wallFinishBasis = $wallArea;
        if ($wallFinishBasis === null && $floorArea !== null && $floorArea->evidenceIds !== []) {
            $wallFinishBasis = $this->scaled(
                'internal_wall_finish_area',
                'm2',
                $floorArea,
                '3.00',
                ['preliminary_internal_wall_finish_area_factor:3.00'],
            );
        }
        if ($wallFinishBasis !== null && $wallFinishBasis->evidenceIds !== []) {
            $quantities['finish.paint'] = $this->subtract(
                'finish.paint',
                $wallFinishBasis,
                $quantities['sanitary.tile'] ?? null,
                ['paint_area_excludes_evidenced_wet_room_wall_tile'],
            );
        }

        $omissions[] = $this->omission('electrical.trays', 'not_applicable_to_residential_preliminary_scenario');
        $omissions[] = $this->omission('networks.external', 'external_network_route_missing');
        $omissions[] = $this->omission('site.geodesy', 'site_geodetic_inputs_missing');
        $omissions[] = $this->omission('site.setup', 'site_preparation_scope_missing');

        ksort($quantities, SORT_STRING);
        usort($omissions, static fn (array $left, array $right): int => $left['quantity_key'] <=> $right['quantity_key']);

        return new ResidentialQuantityScenarioResult($quantities, $omissions);
    }

    public static function owns(QuantityData $quantity): bool
    {
        return isset(self::UNITS[$quantity->key])
            && $quantity->unit === self::UNITS[$quantity->key]
            && $quantity->formulaVersion === self::VERSION
            && $quantity->formulaKey === 'residential_preliminary.'.$quantity->key
            && in_array(self::SCENARIO_ID, $quantity->assumptions, true)
            && ($quantity->formulaInputs['scenario']['id'] ?? null) === self::SCENARIO_ID
            && ($quantity->formulaInputs['scenario']['version'] ?? null) === self::VERSION
            && ($quantity->formulaInputs['scenario']['confidence'] ?? null) === 0.62
            && ($quantity->formulaInputs['scenario']['warnings'] ?? null) === ['preliminary_quantity_scenario']
            && $quantity->reviewBlockers === [];
    }

    private function supports(array $analysis): bool
    {
        $object = is_array($analysis['object'] ?? null) ? $analysis['object'] : [];
        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        $factsSummary = is_array($documentContext['facts_summary'] ?? null) ? $documentContext['facts_summary'] : [];

        $objectTypeDecision = $this->structuredResidentialDecision([
            $object['object_type'] ?? null,
            $object['building_type'] ?? null,
        ]);
        if ($objectTypeDecision !== null) {
            return $objectTypeDecision;
        }

        $documentTypeDecision = $this->structuredResidentialDecision([
            $factsSummary['object_type'] ?? null,
            $factsSummary['building_type'] ?? null,
        ]);
        if ($documentTypeDecision !== null) {
            return $documentTypeDecision;
        }

        foreach ([
            $factsSummary['description'] ?? null,
            $object['description'] ?? null,
            $object['manual_description'] ?? null,
            $documentContext['context_text'] ?? null,
        ] as $value) {
            if (is_string($value) && ObjectTypeSignalClassifier::isResidential($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function structuredResidentialDecision(array $values): ?bool
    {
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $normalized = mb_strtolower(trim($value));
            if ($normalized === '' || in_array($normalized, [
                'building',
                'custom',
                'floor_plan_geometry',
                'generic',
                'object',
                'unknown',
            ], true)) {
                continue;
            }

            return ObjectTypeSignalClassifier::isResidential($value);
        }

        return null;
    }

    private function scenarioBasis(?QuantityData $quantity, NormalizedBuildingModelData $model): ?QuantityData
    {
        if ($quantity === null || $quantity->evidenceIds !== []) {
            return $quantity;
        }
        if ($quantity->source !== QuantitySource::Estimated
            || $quantity->reviewBlockers !== ['estimated_quantity_requires_review']
            || $model->scaleStatus !== 'confirmed'
            || $model->evidenceIds === []) {
            return null;
        }

        return new QuantityData(
            key: $quantity->key,
            unit: $quantity->unit,
            amount: $quantity->amount,
            formulaKey: $quantity->formulaKey,
            formulaVersion: $quantity->formulaVersion,
            formulaInputs: $quantity->formulaInputs,
            source: QuantitySource::Estimated,
            evidenceIds: array_values(array_unique(array_map('strval', $model->evidenceIds))),
            modelVersion: $model->modelVersion,
            assumptions: array_values(array_unique([
                ...$quantity->assumptions,
                'preliminary_total_area_with_confirmed_geometry',
            ])),
            reviewBlockers: [],
        );
    }

    private function quantity(mixed $quantity): ?QuantityData
    {
        if ($quantity instanceof QuantityData) {
            return $quantity;
        }

        return is_array($quantity) ? QuantityData::fromArray($quantity) : null;
    }

    private function scaled(string $key, string $unit, QuantityData $source, string $factor, array $assumptions): QuantityData
    {
        $amount = BigDecimal::of($source->amount)->multipliedBy($factor)->toScale(6, RoundingMode::HalfUp);

        return $this->make(
            $key,
            $unit,
            (string) $amount,
            ['source_quantity' => $this->sourceReference($source), 'factor' => $factor],
            $source->evidenceIds,
            $source->modelVersion,
            [...$source->assumptions, ...$assumptions],
        );
    }

    private function subtract(
        string $key,
        QuantityData $minuend,
        ?QuantityData $subtrahend,
        array $assumptions,
    ): QuantityData {
        $amount = BigDecimal::of($minuend->amount)
            ->minus($subtrahend?->amount ?? '0');
        if ($amount->compareTo(BigDecimal::zero()) < 0) {
            $amount = BigDecimal::zero();
        }

        return $this->make(
            $key,
            'm2',
            (string) $amount->toScale(6, RoundingMode::HalfUp),
            [
                'total_area' => $this->sourceReference($minuend),
                'excluded_area' => $subtrahend === null ? null : $this->sourceReference($subtrahend),
            ],
            array_values(array_unique([
                ...$minuend->evidenceIds,
                ...($subtrahend?->evidenceIds ?? []),
            ])),
            $minuend->modelVersion,
            [
                ...$minuend->assumptions,
                ...($subtrahend?->assumptions ?? []),
                ...$assumptions,
            ],
        );
    }

    private function equivalentPerimeter(string $key, QuantityData $source, string $factor, array $assumptions): QuantityData
    {
        $amount = BigDecimal::of((string) (sqrt((float) $source->amount) * 4))
            ->multipliedBy($factor)
            ->toScale(6, RoundingMode::HalfUp);

        return $this->make(
            $key,
            'm',
            (string) $amount,
            [
                'source_quantity' => $this->sourceReference($source),
                'equivalent_perimeter_formula' => '4*sqrt(area)',
                'factor' => $factor,
            ],
            $source->evidenceIds,
            $source->modelVersion,
            [...$source->assumptions, ...$assumptions],
        );
    }

    private function countBased(
        string $key,
        string $unit,
        int $count,
        string $factor,
        NormalizedBuildingModelData $model,
        string $operandKey,
        array $assumptions,
    ): QuantityData {
        $amount = BigDecimal::of((string) $count)->multipliedBy($factor)->toScale(6, RoundingMode::HalfUp);

        return $this->make(
            $key,
            $unit,
            (string) $amount,
            [$operandKey => $count, 'factor' => $factor],
            array_map('strval', $model->evidenceIds),
            $model->modelVersion,
            $assumptions,
        );
    }

    private function radiatorDeviceQuantity(QuantityData $floorArea, array $areaBasisAssumptions): QuantityData
    {
        $heatLoadPerSquareMeterKw = BigDecimal::of('0.10');
        $sectionOutputKw = BigDecimal::of('0.18');
        $sectionsPerEmitter = BigDecimal::of('8');
        $emitterOutputKw = $sectionOutputKw->multipliedBy($sectionsPerEmitter);
        $heatLoadKw = BigDecimal::of($floorArea->amount)->multipliedBy($heatLoadPerSquareMeterKw);
        $unroundedEmitterCount = $heatLoadKw->dividedBy($emitterOutputKw, 8, RoundingMode::HalfUp);
        $emitterCount = $unroundedEmitterCount->toScale(0, RoundingMode::Ceiling);

        return $this->make(
            'heating.radiators',
            'pcs',
            (string) $emitterCount->toScale(6),
            [
                'source_quantity' => $this->sourceReference($floorArea),
                'heat_load_per_m2_kw' => (string) $heatLoadPerSquareMeterKw,
                'radiator_section_output_kw' => (string) $sectionOutputKw,
                'radiator_sections_per_emitter' => (string) $sectionsPerEmitter,
                'radiator_output_kw' => (string) $emitterOutputKw,
                'unrounded_emitter_count' => (string) $unroundedEmitterCount,
            ],
            $floorArea->evidenceIds,
            $floorArea->modelVersion,
            [
                ...$floorArea->assumptions,
                'residential_heat_load_kw_per_m2:0.10',
                'residential_radiator_section_output_kw:0.18',
                'residential_radiator_sections_per_emitter:8',
                ...$areaBasisAssumptions,
            ],
        );
    }

    private function make(
        string $key,
        string $unit,
        string $amount,
        array $formulaInputs,
        array $evidenceIds,
        string $modelVersion,
        array $assumptions,
    ): QuantityData {
        $evidenceIds = array_values(array_unique(array_map('strval', $evidenceIds)));
        sort($evidenceIds, SORT_NATURAL);

        return new QuantityData(
            key: $key,
            unit: $unit,
            amount: $amount,
            formulaKey: 'residential_preliminary.'.$key,
            formulaVersion: self::VERSION,
            formulaInputs: [
                ...$formulaInputs,
                'scenario' => [
                    'id' => self::SCENARIO_ID,
                    'version' => self::VERSION,
                    'confidence' => 0.62,
                    'warnings' => ['preliminary_quantity_scenario'],
                ],
            ],
            source: QuantitySource::Estimated,
            evidenceIds: $evidenceIds,
            modelVersion: $modelVersion,
            assumptions: array_values(array_unique([self::SCENARIO_ID, ...$assumptions])),
            reviewBlockers: [],
        );
    }

    private function sourceReference(QuantityData $source): array
    {
        return [
            'key' => $source->key,
            'unit' => $source->unit,
            'amount' => $source->amount,
            'formula_key' => $source->formulaKey,
            'formula_version' => $source->formulaVersion,
            'model_version' => $source->modelVersion,
        ];
    }

    private function isServiceRoom(RoomData $room): bool
    {
        return preg_match(
            '/(?:котельн|бойлер|тех(?:ническ)?\.?\s*помещ|boiler|utility)/iu',
            mb_strtolower((string) $room->name),
        ) === 1;
    }

    private function isFinishedWetRoom(RoomData $room): bool
    {
        return preg_match(
            '/(?:сануз|(?:^|\s)су(?=\s|$)|с\s*\/\s*у|ванн|душ|туалет|bath|shower|wc)/iu',
            mb_strtolower((string) $room->name),
        ) === 1;
    }

    private function isBathOrShowerRoom(RoomData $room): bool
    {
        return preg_match(
            '/(?:ванн|душ|bath|shower)/iu',
            mb_strtolower((string) $room->name),
        ) === 1;
    }

    /** @return array{room_key: string, area: BigDecimal, evidence_ids: list<string>}|null */
    private function roomArea(RoomData $room): ?array
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:м(?:2|²))?\s*$/iu', (string) $room->name, $matches) !== 1) {
            return null;
        }

        $area = BigDecimal::of(str_replace(',', '.', $matches[1]));
        if ($area->isLessThanOrEqualTo(0) || $area->isGreaterThan(200)) {
            return null;
        }

        return [
            'room_key' => $room->key,
            'area' => $area,
            'evidence_ids' => array_values(array_unique(array_map('strval', $room->evidenceIds))),
        ];
    }

    private function omission(string $quantityKey, string $reason): array
    {
        return [
            'quantity_key' => $quantityKey,
            'reason' => $reason,
            'package_key' => match (strstr($quantityKey, '.', true) ?: $quantityKey) {
                'earth' => 'earthworks',
                'lighting' => 'electrical',
                'networks' => 'external_networks',
                'sanitary' => 'plumbing',
                default => strstr($quantityKey, '.', true) ?: $quantityKey,
            },
        ];
    }
}
