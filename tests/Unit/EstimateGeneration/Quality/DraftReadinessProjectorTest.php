<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftReadinessProjector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DraftReadinessProjectorTest extends TestCase
{
    #[Test]
    public function it_persists_completeness_without_weakening_existing_readiness_summary(): void
    {
        $projected = (new DraftReadinessProjector)->project([
            'object_profile' => ['object_type' => 'house', 'floors' => 2],
            'package_plan' => ['packages' => [[
                'key' => 'heating',
                'title' => 'Отопление',
                'coverage_required' => true,
            ]]],
            'local_estimates' => [[
                'key' => 'heating',
                'sections' => [['work_items' => [[
                    'item_type' => 'priced_work',
                    'metadata' => ['composition_work_key' => 'heating.unit'],
                ]]]],
            ]],
            'building_model' => [
                'scale_status' => 'confirmed',
                'evidence_ids' => [1],
                'metrics' => ['complete' => true],
            ],
            'quality_summary' => ['review_items' => ['blocking' => 0]],
        ]);

        self::assertSame('confirmed_scope_only', $projected['completeness']['status']);
        self::assertSame('confirmed_scope_only', $projected['quality_summary']['completeness_status']);
        self::assertSame('review_required', $projected['quality_summary']['status']);
        self::assertContains('required_scope_unresolved', $projected['quality_summary']['critical_flags']);
    }

    #[Test]
    public function it_preserves_direct_costs_without_promoting_confirmed_scope_only_to_a_commercial_budget(): void
    {
        $projected = (new DraftReadinessProjector)->project([
            'object_profile' => ['object_type' => 'house', 'floors' => 2],
            'package_plan' => ['packages' => [[
                'key' => 'heating',
                'title' => 'heating',
                'coverage_required' => true,
            ]]],
            'local_estimates' => [[
                'key' => 'heating',
                'sections' => [['work_items' => [[
                    'item_type' => 'priced_work',
                    'total_cost' => 1200.0,
                    'metadata' => ['composition_work_key' => 'heating.unit'],
                ]]]],
            ]],
            'budget_calculation' => [
                'overhead' => ['status' => 'calculated', 'amount' => 200.0],
                'profit' => ['status' => 'calculated', 'amount' => 100.0],
            ],
        ]);

        self::assertSame(1200.0, $projected['budget_scope']['direct_costs']);
        self::assertSame('not_calculated', $projected['budget_scope']['overhead']['status']);
        self::assertNull($projected['budget_scope']['overhead']['amount']);
        self::assertSame('not_calculated', $projected['budget_scope']['profit']['status']);
        self::assertNull($projected['budget_scope']['profit']['amount']);
        self::assertSame('not_calculated', $projected['budget_scope']['commercial_budget']['status']);
        self::assertNull($projected['budget_scope']['commercial_budget']['amount']);
        self::assertSame('confirmed_scope_only', $projected['budget_scope']['claim']);
    }
}
