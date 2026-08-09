<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

final class ProjectPortfolioHealthSnapshotEvidence
{
    public function isComplete(
        ?int $actualAsOf,
        int $expectedAsOf,
        string $actualVersion,
        string $expectedVersion,
        string $qualityStatus,
        int $declaredRowCount,
        int $coverageNumerator,
        int $coverageDenominator,
        int $actualRowCount,
    ): bool {
        return $actualAsOf === $expectedAsOf
            && $actualVersion === $expectedVersion
            && $qualityStatus === 'complete'
            && $declaredRowCount === $actualRowCount
            && $coverageNumerator === $actualRowCount
            && $coverageDenominator === $actualRowCount;
    }
}
