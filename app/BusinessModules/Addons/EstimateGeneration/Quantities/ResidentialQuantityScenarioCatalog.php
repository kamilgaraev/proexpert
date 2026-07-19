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
    public const VERSION = '2.2.0';

    public const SCENARIO_ID = 'residential_preliminary_scenario:v5';

    private const UNITS = [
        'electrical.grounding' => 'm',
        'electrical.main_cable' => 'm',
        'electrical.power_lines' => 'm',
        'heating.pipe' => 'm',
        'heating.unit' => 'pcs',
        'lighting.lines' => 'm',
        'openings.doors' => 'm2',
        'openings.windows' => 'm2',
        'plumbing.pipe' => 'm',
        'roof.area' => 'm2',
        'roof.flat_area' => 'm2',
        'roof.gutter' => 'm',
        'roof.rafters' => 'm2',
        'sanitary.points' => 'pcs',
        'sanitary.tile' => 'm2',
        'sanitary.waterproofing' => 'm2',
        'sewerage.outlets' => 'pcs',
        'sewerage.pipe' => 'm',
        'sewerage.revisions' => 'pcs',
        'sewerage.risers' => 'pcs',
        'stairs.flights' => 'm2',
        'stairs.landings' => 'm2',
        'stairs.railings' => 'm',
        'ventilation.air_exchange' => 'm2',
    ];

    public function __construct(private readonly RoofTypeResolver $roofTypes = new RoofTypeResolver) {}

    public function build(array $baseQuantities, NormalizedBuildingModelData $model, array $analysis): ResidentialQuantityScenarioResult
    {
        if (! $this->supports($analysis) || $model->scaleStatus !== 'confirmed' || $model->evidenceIds === []) {
            return new ResidentialQuantityScenarioResult([], []);
        }

        $floorArea = $this->quantity($baseQuantities['floor_area'] ?? null);
        $firstFloorArea = $this->quantity($baseQuantities['first_floor_internal_area'] ?? null);
        $floors = $model->floors;
        $floorCount = count($floors);
        $rooms = $floors === []
            ? []
            : array_merge(...array_map(static fn ($floor): array => $floor->rooms, $floors));
        $roomCount = count($rooms);
        $serviceRooms = array_values(array_filter($rooms, fn (RoomData $room): bool => $this->isServiceRoom($room)));
        $quantities = [];
        $omissions = [];
        $roofType = $this->roofTypes->resolve($analysis);

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
                $quantities['roof.rafters'] = $this->scaled(
                    'roof.rafters',
                    'm2',
                    $roofArea,
                    '1.00',
                    ['rafter_system_by_roof_area'],
                );
                $quantities['roof.gutter'] = $this->equivalentPerimeter(
                    'roof.gutter',
                    $firstFloorArea,
                    '1.10',
                    ['equivalent_rectangular_footprint', 'roof_eaves_perimeter_factor:1.10'],
                );
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
            $quantities['heating.unit'] = $this->countBased(
                'heating.unit',
                'pcs',
                1,
                '1',
                $model,
                $serviceRooms !== [] ? 'service_room_count' : 'residential_heat_source_count',
                [$serviceRooms !== [] ? 'one_heat_source_for_documented_service_rooms' : 'one_heat_source_per_house'],
            );
        } else {
            foreach ([
                'openings.windows', 'electrical.main_cable', 'electrical.power_lines', 'lighting.lines',
                'plumbing.pipe', 'sewerage.pipe', 'heating.pipe', 'heating.unit',
                'ventilation.air_exchange',
            ] as $key) {
                $omissions[] = $this->omission($key, 'total_internal_area_missing');
            }
        }

        $omissions[] = $this->omission('heating.radiators', 'radiator_schedule_missing');

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
            $omissions[] = $this->omission('stairs.landings', 'included_in_residential_stair_assembly');
            $omissions[] = $this->omission('stairs.railings', 'stair_railing_geometry_missing');
        } else {
            foreach (['stairs.flights', 'stairs.landings', 'stairs.railings'] as $key) {
                $omissions[] = $this->omission($key, $floorCount === 1 ? 'single_storey_house' : 'floor_count_missing');
            }
        }

        $omissions[] = $this->omission('sanitary.points', 'plumbing_design_takeoff_missing');
        foreach (['sewerage.outlets', 'sewerage.risers', 'sewerage.revisions'] as $key) {
            $omissions[] = $this->omission($key, 'sewerage_design_takeoff_missing');
        }

        $finishedWetRooms = array_values(array_filter($rooms, fn (RoomData $room): bool => $this->isFinishedWetRoom($room)));
        $finishedWetRoomAreas = array_values(array_filter(array_map(
            fn (RoomData $room): ?array => $this->roomArea($room),
            $finishedWetRooms,
        )));
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
            $omissions[] = $this->omission('sanitary.tile', 'wet_zone_finish_specification_missing');
        } else {
            $omissions[] = $this->omission('sanitary.waterproofing', 'finished_wet_room_area_missing');
            $omissions[] = $this->omission('sanitary.tile', 'finished_wet_room_area_missing');
        }

        $omissions[] = $this->omission('electrical.trays', 'not_applicable_to_residential_preliminary_scenario');
        $omissions[] = $this->omission('networks.external', 'external_network_route_missing');
        $omissions[] = $this->omission('foundation.prep', 'foundation_build_up_missing');
        $omissions[] = $this->omission('earth.export', 'soil_balance_missing');

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

        foreach ([
            $object['object_type'] ?? null,
            $object['building_type'] ?? null,
            $object['description'] ?? null,
            $factsSummary['object_type'] ?? null,
            $factsSummary['building_type'] ?? null,
        ] as $value) {
            if (is_string($value) && ObjectTypeSignalClassifier::isResidential($value)) {
                return true;
            }
        }

        return false;
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
            $assumptions,
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
            $assumptions,
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
