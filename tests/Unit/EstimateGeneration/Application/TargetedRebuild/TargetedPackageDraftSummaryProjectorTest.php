<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageDraftSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\ReviewSummarySnapshot;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TargetedPackageDraftSummaryProjectorTest extends TestCase
{
    #[Test]
    public function it_refreshes_totals_and_the_fresh_review_summary_after_one_package_changed(): void
    {
        $draft = (new TargetedPackageDraftSummaryProjector)->project($this->draft());

        self::assertSame(4200.0, $draft['totals']['base_total_cost']);
        self::assertSame(4620.0, $draft['totals']['total_cost']);
        self::assertSame(ReviewSummarySnapshot::VERSION, $draft['quality_summary']['review_items']['classifier_version']);
        self::assertTrue(ReviewSummarySnapshot::isFresh($draft, $draft['quality_summary']['review_items']));
    }

    #[Test]
    public function it_does_not_create_remove_or_change_existing_work_items(): void
    {
        $source = $this->draft();
        $projected = (new TargetedPackageDraftSummaryProjector)->project($source);

        self::assertSame(
            $source['local_estimates'][0]['sections'][0]['work_items'],
            $projected['local_estimates'][0]['sections'][0]['work_items'],
        );
        self::assertSame(
            $source['local_estimates'][1]['sections'][0]['work_items'],
            $projected['local_estimates'][1]['sections'][0]['work_items'],
        );
    }

    /** @return array<string, mixed> */
    private function draft(): array
    {
        return [
            'source_input_version' => 'sha256:'.str_repeat('a', 64),
            'contingency_percent' => 10.0,
            'object_profile' => ['object_type' => 'house', 'floors' => 2],
            'package_plan' => ['packages' => [
                ['key' => 'foundation', 'title' => 'Foundation', 'coverage_required' => true],
                ['key' => 'heating', 'title' => 'Heating', 'coverage_required' => true],
            ]],
            'building_model' => [
                'scale_status' => 'confirmed',
                'evidence_ids' => [1],
                'metrics' => ['complete' => true],
            ],
            'local_estimates' => [
                $this->localEstimate('foundation', 'foundation.work', 1200.0),
                $this->localEstimate('heating', 'heating.work', 3000.0),
            ],
            'problem_flags' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function localEstimate(string $key, string $workItemKey, float $totalCost): array
    {
        return [
            'key' => $key,
            'title' => ucfirst($key),
            'sections' => [[
                'key' => $key.'.section',
                'title' => ucfirst($key),
                'work_items' => [[
                    'key' => $workItemKey,
                    'item_type' => 'priced_work',
                    'name' => ucfirst($key).' work',
                    'total_cost' => $totalCost,
                    'pricing_status' => 'calculated',
                    'normative_match' => [
                        'status' => 'matched',
                        'decision' => ['status' => 'accepted'],
                    ],
                    'validation_flags' => [],
                    'metadata' => ['composition_work_key' => $workItemKey],
                ]],
            ]],
        ];
    }
}
