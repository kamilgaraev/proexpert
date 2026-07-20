<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Planning\ResidentialWorkCompositionCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResidentialWorkCompositionCatalogTest extends TestCase
{
    #[Test]
    public function two_storey_pitched_house_has_required_technological_components_without_commercial_templates(): void
    {
        $requirements = (new ResidentialWorkCompositionCatalog)->requirements([
            'object_profile' => [
                'object_type' => 'house',
                'floors' => 2,
                'planning_signals' => ['roof_type' => 'pitched'],
            ],
        ]);

        self::assertContains('foundation.prep', $requirements['foundation']);
        self::assertContains('walls.lintels', $requirements['walls']);
        self::assertSame(
            ['stairs.flights', 'stairs.landings', 'stairs.railings'],
            $requirements['stairs'],
        );
        self::assertSame(
            [
                'roof.rafters', 'roof.vapor_barrier', 'roof.insulation',
                'roof.membrane', 'roof.battens', 'roof.covering', 'roof.gutter',
            ],
            $requirements['roof'],
        );
        self::assertContains('sanitary.showers', $requirements['plumbing']);
        self::assertContains('sanitary.toilets', $requirements['plumbing']);
        self::assertContains('sanitary.washbasins', $requirements['plumbing']);
        self::assertSame(['sewerage.pipe', 'sewerage.outlet_route'], $requirements['sewerage']);
        self::assertContains('heating.unit', $requirements['heating']);
        self::assertContains('heating.radiators', $requirements['heating']);
        self::assertContains('ventilation.distribution_devices', $requirements['ventilation']);

        $all = array_merge(...array_values($requirements));
        self::assertSame([], array_values(array_filter(
            $all,
            static fn (string $key): bool => str_starts_with($key, 'office.') || str_starts_with($key, 'warehouse.'),
        )));
    }

    #[Test]
    public function stairs_and_pitched_roof_structure_are_not_required_when_the_object_rules_exclude_them(): void
    {
        $requirements = (new ResidentialWorkCompositionCatalog)->requirements([
            'object_profile' => [
                'object_type' => 'house',
                'floors' => 1,
                'planning_signals' => ['roof_type' => 'flat'],
            ],
        ]);

        self::assertSame([], $requirements['stairs']);
        self::assertNotContains('roof.rafters', $requirements['roof']);
        self::assertSame([
            'roof.flat.base', 'roof.flat.vapor_barrier',
            'roof.flat.insulation', 'roof.flat.waterproofing',
        ], $requirements['roof']);
    }

    #[Test]
    public function catalog_is_not_applied_to_non_residential_objects(): void
    {
        self::assertSame([], (new ResidentialWorkCompositionCatalog)->requirements([
            'object_profile' => ['object_type' => 'mixed_warehouse_office'],
        ]));
    }

    #[Test]
    public function unknown_roof_type_never_assumes_a_pitched_roof_assembly(): void
    {
        $requirements = (new ResidentialWorkCompositionCatalog)->requirements([
            'object_profile' => ['object_type' => 'house', 'floors' => 2],
        ]);

        self::assertSame(['roof.area'], $requirements['roof']);
    }
}
