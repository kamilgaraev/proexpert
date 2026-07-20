<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftResidentialCompositionInspector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DraftResidentialCompositionInspectorTest extends TestCase
{
    #[Test]
    public function one_item_does_not_make_a_required_house_system_complete(): void
    {
        $missing = (new DraftResidentialCompositionInspector)->missingRequirements([
            'object_profile' => ['object_type' => 'house', 'floors' => 2],
            'package_plan' => ['packages' => [[
                'key' => 'heating', 'title' => 'Отопление', 'coverage_required' => true,
            ]]],
            'local_estimates' => [[
                'key' => 'heating',
                'sections' => [['work_items' => [[
                    'item_type' => 'priced_work',
                    'metadata' => ['quantity_key' => 'heating.pipe'],
                ]]]],
            ]],
        ]);

        self::assertSame([[
            'key' => 'heating',
            'title' => 'Отопление',
            'missing_items' => ['heating.radiators'],
        ]], $missing);
    }

    #[Test]
    public function material_scenario_identity_distinguishes_roof_layers_sharing_one_area(): void
    {
        $items = array_map(static fn (string $key): array => [
            'item_type' => 'priced_work',
            'metadata' => [
                'quantity_key' => in_array($key, ['roof.insulation', 'roof.covering'], true) ? 'roof.area' : $key,
                ...in_array($key, ['roof.insulation', 'roof.covering'], true)
                    ? ['material_scenario_work_key' => $key]
                    : [],
            ],
        ], ['roof.rafters', 'roof.insulation', 'roof.covering', 'roof.gutter']);

        self::assertSame([], (new DraftResidentialCompositionInspector)->missingRequirements([
            'object_profile' => [
                'object_type' => 'house',
                'floors' => 2,
                'planning_signals' => ['roof_type' => 'pitched'],
            ],
            'package_plan' => ['packages' => [[
                'key' => 'roof', 'title' => 'Кровля', 'coverage_required' => true,
            ]]],
            'local_estimates' => [['key' => 'roof', 'sections' => [['work_items' => $items]]]],
        ]));
    }
}
