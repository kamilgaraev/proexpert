<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\ProjectModel;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DecisionEvidenceLineage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DecisionEvidenceLineageTest extends TestCase
{
    #[Test]
    public function ambiguous_or_unproven_historical_conflict_never_counts_as_decision_evidence(): void
    {
        self::assertTrue(DecisionEvidenceLineage::isTrusted([
            ['evidence_id' => 'evidence:1', 'source_version' => 'sha256:a', 'invalidation_version' => 1],
        ], ['evidence:1']));
        foreach (['historical_conflict_ambiguous', 'historical_conflict_unproven', 'historical_evidence_unproven'] as $marker) {
            self::assertFalse(DecisionEvidenceLineage::isTrusted([
                ['evidence_id' => 'evidence:1'], ['limitation_code' => $marker],
            ], ['evidence:1']), $marker);
        }
    }
}
