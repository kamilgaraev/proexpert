<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\DTO;

final readonly class PayrollReadinessMetrics
{
    /** @param array<string, string> $sourceAmounts */
    public function __construct(
        public int $sourceRowCount,
        public string $sourceHours,
        public array $sourceAmounts,
        public ?string $coveragePercent,
        public int $blockingIssueCount,
        public int $warningCount,
        public ?string $issueRate,
        public string $unassignedHours,
        public string $unratedHours,
        public bool $ready,
        public string $readinessState,
    ) {
    }
}
