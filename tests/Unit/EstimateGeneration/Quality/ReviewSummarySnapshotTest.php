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

    public function test_jsonb_object_order_and_final_artifact_signature_do_not_change_the_review_business_version(): void
    {
        $row = ['key' => 'finish:1', 'name' => 'Отделка', 'quantity' => '20', 'unit' => 'm2',
            'metadata' => ['stage6_provenance' => ['artifact' => ['contract' => 'most_ordinary_estimate:v1', 'artifact_hash' => str_repeat('a', 64)]]]];
        $draft = ['source_input_version' => 'sha256:'.str_repeat('a', 64), 'local_estimates' => [['sections' => [['work_items' => [$row]]]]]];
        $draft['quality_summary']['content_version'] = ReviewSummarySnapshot::contentVersion($draft);
        $summary = ReviewSummarySnapshot::create($draft, ['total' => 1]);
        $draft['local_estimates'][0]['sections'][0]['work_items'][0] = array_reverse($row, true);
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['metadata']['stage6_provenance']['artifact']['artifact_hash'] = str_repeat('b', 64);
        self::assertTrue(ReviewSummarySnapshot::isFresh($draft, $summary));
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['quantity'] = '21';
        self::assertFalse(ReviewSummarySnapshot::isFresh($draft, $summary));
    }
}
