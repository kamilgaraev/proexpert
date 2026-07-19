<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

final class QuantityCoverageWarning
{
    private const REASONS = [
        'electrical_design_takeoff_missing',
        'external_gutter_not_inferred_for_flat_roof',
        'external_network_route_missing',
        'floor_count_missing',
        'foundation_build_up_missing',
        'grounding_installation_type_missing',
        'heating_design_takeoff_missing',
        'not_applicable_to_residential_preliminary_scenario',
        'opening_schedule_missing',
        'plumbing_design_takeoff_missing',
        'radiator_schedule_missing',
        'roof_drainage_takeoff_missing',
        'roof_geometry_takeoff_missing',
        'roof_structure_geometry_missing',
        'sewerage_design_takeoff_missing',
        'single_storey_house',
        'soil_balance_missing',
        'stair_construction_geometry_missing',
        'stair_railing_geometry_missing',
        'ventilation_duct_takeoff_missing',
        'window_schedule_missing',
    ];

    /** @return list<string> */
    public static function reasons(): array
    {
        return self::REASONS;
    }

    public static function isValid(mixed $warning): bool
    {
        if (! is_array($warning)) {
            return false;
        }

        foreach (['quantity_key', 'reason', 'package_key'] as $key) {
            $value = $warning[$key] ?? null;
            if (! is_string($value) || preg_match('/^[a-z0-9][a-z0-9_.-]*$/D', $value) !== 1) {
                return false;
            }
        }

        return in_array($warning['reason'], self::REASONS, true);
    }
}
