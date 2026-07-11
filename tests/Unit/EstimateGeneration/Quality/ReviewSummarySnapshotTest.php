<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\ReviewSummarySnapshot;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReviewSummarySnapshotTest extends TestCase
{
    #[Test]
    public function source_version_mismatch_invalidates_the_snapshot(): void
    {
        $draft = ['local_estimates' => [['sections' => [['work_items' => [[
            'item_type' => 'priced_work',
            'quantity' => 1,
            'total_cost' => 100,
            'normative_match' => ['decision' => ['status' => 'accepted']],
            'normative_candidates' => [],
        ]]]]]]];
        $draft['quality_summary']['content_version'] = ReviewSummarySnapshot::contentVersion($draft);
        $snapshot = ReviewSummarySnapshot::create($draft, ['total' => 0, 'blocking' => 0, 'warning' => 0, 'optional' => 0]);
        self::assertTrue(ReviewSummarySnapshot::isFresh($draft, $snapshot));

        $draft['quality_summary']['content_version'] = 'sha256:'.str_repeat('b', 64);
        self::assertFalse(ReviewSummarySnapshot::isFresh($draft, $snapshot));
    }
}
