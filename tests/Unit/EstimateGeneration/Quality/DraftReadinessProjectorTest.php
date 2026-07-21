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
}
