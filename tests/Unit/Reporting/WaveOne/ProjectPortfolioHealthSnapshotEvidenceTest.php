<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthSnapshotEvidence;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectPortfolioHealthSnapshotEvidenceTest extends TestCase
{
    #[Test]
    public function complete_zero_row_snapshot_is_valid_source_evidence(): void
    {
        self::assertTrue((new ProjectPortfolioHealthSnapshotEvidence)->isComplete(
            actualAsOf: 1786233600,
            expectedAsOf: 1786233600,
            actualVersion: 'portfolio-margin-v1|portfolio-margin-source-v1',
            expectedVersion: 'portfolio-margin-v1|portfolio-margin-source-v1',
            qualityStatus: 'complete',
            declaredRowCount: 0,
            coverageNumerator: 0,
            coverageDenominator: 0,
            actualRowCount: 0,
        ));
    }

    #[Test]
    public function mismatched_row_count_is_not_valid_source_evidence(): void
    {
        self::assertFalse((new ProjectPortfolioHealthSnapshotEvidence)->isComplete(
            actualAsOf: 1786233600,
            expectedAsOf: 1786233600,
            actualVersion: 'portfolio-margin-v1|portfolio-margin-source-v1',
            expectedVersion: 'portfolio-margin-v1|portfolio-margin-source-v1',
            qualityStatus: 'complete',
            declaredRowCount: 1,
            coverageNumerator: 1,
            coverageDenominator: 1,
            actualRowCount: 0,
        ));
    }
}
