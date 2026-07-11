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
}
