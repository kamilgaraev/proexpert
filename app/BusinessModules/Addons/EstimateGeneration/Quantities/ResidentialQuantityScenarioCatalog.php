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
    public const VERSION = '1.0.0';

    private const ASSUMPTION = 'residential_preliminary_scenario:v1';

    private const UNITS = [
        'electrical.grounding' => 'm',
        'electrical.main_cable' => 'm',
        'electrical.power_lines' => 'm',
        'heating.pipe' => 'm',
        'heating.radiators' => 'pcs',
        'lighting.lines' => 'm',
        'openings.doors' => 'm2',
        'openings.windows' => 'm2',
        'plumbing.pipe' => 'm',
        'roof.area' => 'm2',
        'roof.flat_area' => 'm2',
        'roof.gutter' => 'm',
        'sanitary.points' => 'pcs',
        'sewerage.outlets' => 'pcs',
        'sewerage.pipe' => 'm',
        'sewerage.risers' => 'pcs',
        'stairs.flights' => 'm2',
        'stairs.landings' => 'm2',
        'stairs.railings' => 'm',
        'ventilation.air_exchange' => 'm2',
    ];

    public function __construct(private readonly RoofTypeResolver $roofTypes = new RoofTypeResolver) {}

    public function build(array $baseQuantities, NormalizedBuildingModelData $model, array $analysis): ResidentialQuantityScenarioResult
    {
        if (! $this->supports($analysis)) {
            return new ResidentialQuantityScenarioResult([], []);
        }

        $floorArea = $this->quantity($baseQuantities['floor_area'] ?? null);
        $firstFloorArea = $this->quantity($baseQuantities['first_floor_internal_area'] ?? null);
        $floorCount = count($model->floors);
        $rooms = array_merge(...array_map(static fn ($floor): array => $floor->rooms, $model->floors));
        $roomCount = count($rooms);
        $wetRooms = array_values(array_filter($rooms, fn (RoomData $room): bool => $this->isWetRoom($room)));
        $quantities = [];
        $omissions = [];
        $roofType = $this->roofTypes->resolve($analysis);

        if ($firstFloorArea !== null && $firstFloorArea->evidenceIds !== []) {
            if ($roofType === 'pitched') {
                $quantities['roof.area'] = $this->scaled(
                    'roof.area',
                    'm2',
                    $firstFloorArea,
                    '1.35',
                    'residential_preliminary.roof.area',
                    ['pitched_roof_area_factor:1.35'],
                );
                $quantities['roof.gutter'] = $this->equivalentPerimeter(
                    'roof.gutter',
                    $firstFloorArea,
                    '1.10',
                    ['equivalent_rectangular_footprint', 'roof_overhang_perimeter_factor:1.10'],
                );
            } elseif ($roofType === 'flat') {
                $quantities['roof.flat_area'] = $this->scaled(
                    'roof.flat_area',
                    'm2',
                    $firstFloorArea,
                    '1.00',
                    'residential_preliminary.roof.flat_area',
                    ['flat_roof_projection_factor:1.00'],
                );
                $omissions[] = $this->omission('roof.gutter', 'external_gutter_not_inferred_for_flat_roof');
            } else {
                $quantities['roof.area'] = $this->scaled(
                    'roof.area',
                    'm2',
                    $firstFloorArea,
                    '1.00',
                    'residential_preliminary.roof.area',
                    ['generic_roof_projection_factor:1.00'],
                );
                $omissions[] = $this->omission('roof.gutter', 'roof_type_missing');
            }
            $quantities['electrical.grounding'] = $this->equivalentPerimeter(
                'electrical.grounding',
                $firstFloorArea,
                '1.00',
                ['equivalent_rectangular_footprint'],
            );
        } else {
            $omissions[] = $this->omission('roof.area', 'first_floor_internal_area_missing');
            $omissions[] = $this->omission('roof.gutter', 'first_floor_internal_area_missing');
            $omissions[] = $this->omission('electrical.grounding', 'first_floor_internal_area_missing');
        }

        if ($floorArea !== null && $floorArea->evidenceIds !== []) {
            foreach ([
                'openings.windows' => ['m2', '0.12', 'window_area_ratio:0.12'],
                'electrical.main_cable' => ['m', '0.40', 'electrical_main_length_ratio:0.40'],
                'electrical.power_lines' => ['m', '0.80', 'electrical_power_length_ratio:0.80'],
                'lighting.lines' => ['m', '0.80', 'lighting_length_ratio:0.80'],
                'plumbing.pipe' => ['m', '0.35', 'water_pipe_length_ratio:0.35'],
                'sewerage.pipe' => ['m', '0.25', 'sewer_pipe_length_ratio:0.25'],
                'heating.pipe' => ['m', '0.50', 'heating_pipe_length_ratio:0.50'],
                'ventilation.air_exchange' => ['m2', '0.30', 'duct_surface_area_ratio:0.30'],
            ] as $key => [$unit, $factor, $assumption]) {
                $quantities[$key] = $this->scaled(
                    $key,
                    $unit,
                    $floorArea,
                    $factor,
                    'residential_preliminary.'.$key,
                    [$assumption],
                );
            }
        } else {
            foreach ([
                'openings.windows', 'electrical.main_cable', 'electrical.power_lines',
                'lighting.lines', 'plumbing.pipe', 'sewerage.pipe', 'heating.pipe', 'ventilation.air_exchange',
            ] as $key) {
                $omissions[] = $this->omission($key, 'total_internal_area_missing');
            }
        }

        if ($roomCount > 0 && $model->evidenceIds !== []) {
            $quantities['openings.doors'] = $this->countBased(
                'openings.doors',
                'm2',
                $roomCount,
                '1.8',
                $model,
                'room_count',
                ['one_internal_door_per_annotated_room', 'door_area_per_room_m2:1.8'],
            );
            $quantities['heating.radiators'] = $this->countBased(
                'heating.radiators',
                'pcs',
                $roomCount,
                '1',
                $model,
                'room_count',
                ['one_heating_emitter_per_annotated_room'],
            );
        } else {
            $omissions[] = $this->omission('openings.doors', 'room_annotations_missing');
            $omissions[] = $this->omission('heating.radiators', 'room_annotations_missing');
        }

        if ($floorCount > 1 && $model->evidenceIds !== []) {
            $transitions = $floorCount - 1;
            foreach ([
                'stairs.flights' => ['m2', '8', 'stair_flight_area_per_transition_m2:8'],
                'stairs.landings' => ['m2', '4', 'stair_landing_area_per_transition_m2:4'],
                'stairs.railings' => ['m', '8', 'stair_railing_length_per_transition_m:8'],
            ] as $key => [$unit, $factor, $assumption]) {
                $quantities[$key] = $this->countBased(
                    $key,
                    $unit,
                    $transitions,
                    $factor,
                    $model,
                    'interfloor_transition_count',
                    [$assumption],
                );
            }
        } else {
            foreach (['stairs.flights', 'stairs.landings', 'stairs.railings'] as $key) {
                $omissions[] = $this->omission($key, $floorCount === 1 ? 'single_storey_house' : 'floor_count_missing');
            }
        }

        if ($wetRooms !== [] && $model->evidenceIds !== []) {
            $wetRoomCount = count($wetRooms);
            $quantities['sanitary.points'] = $this->countBased(
                'sanitary.points',
                'pcs',
                $wetRoomCount,
                '2',
                $model,
                'wet_room_count',
                ['two_sanitary_connection_points_per_wet_room'],
            );
            foreach ([
                'sewerage.outlets' => ['1', 'one_sewer_outlet_per_wet_room'],
                'sewerage.risers' => ['1', 'one_sewer_riser_per_wet_room'],
            ] as $key => [$factor, $assumption]) {
                $quantities[$key] = $this->countBased(
                    $key,
                    'pcs',
                    $wetRoomCount,
                    $factor,
                    $model,
                    'wet_room_count',
                    [$assumption],
                );
            }
        } else {
            foreach (['sanitary.points', 'sewerage.outlets', 'sewerage.risers'] as $key) {
                $omissions[] = $this->omission($key, 'wet_room_annotations_missing');
            }
        }

        $omissions[] = $this->omission('roof.rafters', 'roof_structure_geometry_missing');
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
            && in_array(self::ASSUMPTION, $quantity->assumptions, true)
            && ($quantity->formulaInputs['scenario']['id'] ?? null) === self::ASSUMPTION
            && ($quantity->formulaInputs['scenario']['confidence'] ?? null) === 0.55
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

    private function scaled(
        string $key,
        string $unit,
        QuantityData $source,
        string $factor,
        string $formulaKey,
        array $assumptions,
    ): QuantityData {
        $amount = BigDecimal::of($source->amount)->multipliedBy($factor)->toScale(6, RoundingMode::HalfUp);

        return $this->make(
            $key,
            $unit,
            (string) $amount,
            $formulaKey,
            ['source_quantity' => $this->sourceReference($source), 'factor' => $factor],
            $source->evidenceIds,
            $source->modelVersion,
            $assumptions,
        );
    }

    private function equivalentPerimeter(
        string $key,
        QuantityData $source,
        string $factor,
        array $assumptions,
    ): QuantityData {
        $area = (float) $source->amount;
        $amount = BigDecimal::of((string) (sqrt($area) * 4))
            ->multipliedBy($factor)
            ->toScale(6, RoundingMode::HalfUp);

        return $this->make(
            $key,
            'm',
            (string) $amount,
            'residential_preliminary.'.$key,
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
            'residential_preliminary.'.$key,
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
        string $formulaKey,
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
            formulaKey: $formulaKey,
            formulaVersion: self::VERSION,
            formulaInputs: [
                ...$formulaInputs,
                'scenario' => [
                    'id' => self::ASSUMPTION,
                    'version' => self::VERSION,
                    'confidence' => 0.55,
                    'warnings' => ['preliminary_quantity_scenario'],
                ],
            ],
            source: QuantitySource::Estimated,
            evidenceIds: $evidenceIds,
            modelVersion: $modelVersion,
            assumptions: array_values(array_unique([self::ASSUMPTION, ...$assumptions])),
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

    private function isWetRoom(RoomData $room): bool
    {
        $name = mb_strtolower((string) $room->name);

        return preg_match('/(?:сануз|с\/у|ванн|душ|постир|котельн|тех\. помещение)/u', $name) === 1;
    }

    private function omission(string $quantityKey, string $reason): array
    {
        return ['quantity_key' => $quantityKey, 'reason' => $reason];
    }
}
