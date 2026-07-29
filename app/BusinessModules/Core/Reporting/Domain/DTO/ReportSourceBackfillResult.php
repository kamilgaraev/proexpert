<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use InvalidArgumentException;

final readonly class ReportSourceBackfillResult
{
    public function __construct(
        public ReportSourceBackfillCursor $cursor,
        public int $eligibleCount,
        public int $projectedCount,
        public int $gapCount,
        public int $unknownCount,
        public string $inputHash,
        public string $outputHash,
    ) {
        if (min($eligibleCount, $projectedCount, $gapCount, $unknownCount) < 0
            || $projectedCount + $gapCount + $unknownCount !== $eligibleCount
            || preg_match('/^[a-f0-9]{64}$/D', $inputHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $outputHash) !== 1
        ) {
            throw new InvalidArgumentException('report_source_backfill_result_invalid');
        }
    }
}
