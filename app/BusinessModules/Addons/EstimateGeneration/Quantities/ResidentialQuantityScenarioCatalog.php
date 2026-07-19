<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\Services\ObjectTypeSignalClassifier;
use App\BusinessModules\Addons\EstimateGeneration\Services\RoofTypeResolver;

final class ResidentialQuantityScenarioCatalog
{
    public const VERSION = '1.1.0';

    public const SCENARIO_ID = 'residential_preliminary_scenario:v2';

    public function __construct(private readonly RoofTypeResolver $roofTypes = new RoofTypeResolver) {}

    public function build(array $baseQuantities, NormalizedBuildingModelData $model, array $analysis): ResidentialQuantityScenarioResult
    {
        if (! $this->supports($analysis)) {
            return new ResidentialQuantityScenarioResult([], []);
        }

        $floorCount = count($model->floors);
        $quantities = [];
        $omissions = [];
        $roofType = $this->roofTypes->resolve($analysis);

        $roofQuantityKey = $roofType === 'flat' ? 'roof.flat_area' : 'roof.area';
        $omissions[] = $this->omission($roofQuantityKey, 'roof_geometry_takeoff_missing');
        $omissions[] = $this->omission(
            'roof.gutter',
            $roofType === 'flat' ? 'external_gutter_not_inferred_for_flat_roof' : 'roof_drainage_takeoff_missing',
        );
        $omissions[] = $this->omission('electrical.grounding', 'grounding_installation_type_missing');

        foreach (['electrical.main_cable', 'electrical.power_lines', 'lighting.lines'] as $key) {
            $omissions[] = $this->omission($key, 'electrical_design_takeoff_missing');
        }
        $omissions[] = $this->omission('plumbing.pipe', 'plumbing_design_takeoff_missing');
        $omissions[] = $this->omission('heating.pipe', 'heating_design_takeoff_missing');
        $omissions[] = $this->omission('openings.doors', 'opening_schedule_missing');
        $omissions[] = $this->omission('openings.windows', 'window_schedule_missing');
        $omissions[] = $this->omission('heating.radiators', 'radiator_schedule_missing');
        $omissions[] = $this->omission('ventilation.air_exchange', 'ventilation_duct_takeoff_missing');

        if ($floorCount > 1 && $model->evidenceIds !== []) {
            $omissions[] = $this->omission('stairs.flights', 'stair_construction_geometry_missing');
            $omissions[] = $this->omission('stairs.landings', 'stair_construction_geometry_missing');
            $omissions[] = $this->omission('stairs.railings', 'stair_railing_geometry_missing');
        } else {
            foreach (['stairs.flights', 'stairs.landings', 'stairs.railings'] as $key) {
                $omissions[] = $this->omission($key, $floorCount === 1 ? 'single_storey_house' : 'floor_count_missing');
            }
        }

        foreach (['sanitary.points'] as $key) {
            $omissions[] = $this->omission($key, 'plumbing_design_takeoff_missing');
        }
        foreach (['sewerage.pipe', 'sewerage.outlets', 'sewerage.risers'] as $key) {
            $omissions[] = $this->omission($key, 'sewerage_design_takeoff_missing');
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
        return false;
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
