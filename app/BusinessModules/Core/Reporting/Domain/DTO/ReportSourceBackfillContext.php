<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ReportSourceBackfillContext
{
    public function __construct(
        public int $organizationId,
        public ReportScope $scope,
        public CarbonImmutable $asOf,
        public string $sourceWatermark,
    ) {
        if (
            $organizationId < 1
            || $organizationId !== $scope->organizationId
            || trim($sourceWatermark) === ''
        ) {
            throw new InvalidArgumentException('report_source_backfill_context_invalid');
        }
    }
}
