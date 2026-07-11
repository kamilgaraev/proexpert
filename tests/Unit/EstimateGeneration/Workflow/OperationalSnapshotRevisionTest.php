<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\OperationalSnapshotRevision;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OperationalSnapshotRevisionTest extends TestCase
{
    #[Test]
    public function revision_is_deterministic_for_equivalent_source_order(): void
    {
        self::assertSame(
            OperationalSnapshotRevision::fromSources(['session' => ['id' => 1], 'usage' => ['count' => 2]]),
            OperationalSnapshotRevision::fromSources(['usage' => ['count' => 2], 'session' => ['id' => 1]]),
        );
    }

    #[Test]
    public function every_observable_source_changes_the_revision(): void
    {
        $sources = [
            'session' => ['state_version' => 1, 'draft_revision' => 'a'],
            'documents' => ['count' => 1, 'max_id' => 1],
            'checkpoint' => ['status' => 'running', 'attempt' => 1],
            'checkpoints' => ['count' => 1, 'max_id' => 1],
            'units' => ['count' => 1, 'max_id' => 1],
            'evidence' => ['count' => 1, 'max_id' => 1],
            'usage' => ['count' => 1, 'tokens' => 4],
            'failures' => ['count' => 1, 'max_sequence' => 1],
            'finalization' => ['count' => 1, 'max_id' => 1],
            'estimate' => ['items' => 1, 'max_item_id' => 1],
            'sources' => ['pages_max_id' => 1, 'facts_max_id' => 1, 'audit_max_id' => 1],
        ];
        $baseline = OperationalSnapshotRevision::fromSources($sources);

        foreach (array_keys($sources) as $source) {
            $changed = $sources;
            $changed[$source]['revision_probe'] = 2;
            self::assertNotSame($baseline, OperationalSnapshotRevision::fromSources($changed), $source);
        }
    }

    #[Test]
    public function every_source_watermark_changes_the_revision_in_place(): void
    {
        $watermarks = [
            'pages_count' => 1,
            'pages_max_id' => 1,
            'pages_max_updated_at' => '2026-07-11T10:00:00+00:00',
            'facts_count' => 1,
            'facts_max_id' => 1,
            'facts_max_updated_at' => '2026-07-11T10:00:00+00:00',
            'drawings_count' => 1,
            'drawings_max_id' => 1,
            'drawings_max_updated_at' => '2026-07-11T10:00:00+00:00',
            'quantities_count' => 1,
            'quantities_max_id' => 1,
            'quantities_max_updated_at' => '2026-07-11T10:00:00+00:00',
            'scopes_count' => 1,
            'scopes_max_id' => 1,
            'scopes_max_updated_at' => '2026-07-11T10:00:00+00:00',
            'edges_count' => 1,
            'edges_max_id' => 1,
            'edges_max_created_at' => '2026-07-11T10:00:00+00:00',
            'feedback_count' => 1,
            'feedback_max_id' => 1,
            'feedback_max_updated_at' => '2026-07-11T10:00:00+00:00',
            'audit_count' => 1,
            'audit_max_id' => 1,
            'audit_max_updated_at' => '2026-07-11T10:00:00+00:00',
            'failure_events_count' => 1,
            'failure_events_max_sequence' => 1,
        ];
        $baseline = OperationalSnapshotRevision::fromSources(['sources' => $watermarks]);

        foreach (array_keys($watermarks) as $watermark) {
            $changed = $watermarks;
            $changed[$watermark] = is_int($changed[$watermark])
                ? $changed[$watermark] + 1
                : '2026-07-11T10:00:01+00:00';

            self::assertNotSame(
                $baseline,
                OperationalSnapshotRevision::fromSources(['sources' => $changed]),
                $watermark,
            );
        }
    }
}
