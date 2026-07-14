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
        $draft = ['source_input_version' => 'sha256:'.str_repeat('a', 64), 'local_estimates' => [['sections' => [['work_items' => [[
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

    #[Test]
    public function input_version_mismatch_or_missing_canonical_versions_invalidates_the_snapshot(): void
    {
        $draft = [
            'source_input_version' => 'sha256:'.str_repeat('a', 64),
            'local_estimates' => [['key' => 'local-1']],
        ];
        $draft['quality_summary']['content_version'] = ReviewSummarySnapshot::contentVersion($draft);
        $snapshot = ReviewSummarySnapshot::create($draft, ['total' => 1]);

        self::assertTrue(ReviewSummarySnapshot::isFresh($draft, $snapshot));

        $draft['source_input_version'] = 'sha256:'.str_repeat('b', 64);
        self::assertFalse(ReviewSummarySnapshot::isFresh($draft, $snapshot));

        unset($draft['source_input_version']);
        self::assertFalse(ReviewSummarySnapshot::isFresh($draft, $snapshot));
    }
}
