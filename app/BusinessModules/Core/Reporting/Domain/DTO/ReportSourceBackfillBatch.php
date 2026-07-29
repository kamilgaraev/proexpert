<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use InvalidArgumentException;

final readonly class ReportSourceBackfillBatch
{
    public function __construct(
        public array $rows,
        public ReportSourceBackfillCursor $nextCursor,
        public bool $hasMore,
        public int $eligibleCount,
        public string $inputHash,
    ) {
        if (! array_is_list($rows)
            || $eligibleCount !== count($rows)
            || preg_match('/^[a-f0-9]{64}$/D', $inputHash) !== 1
        ) {
            throw new InvalidArgumentException('report_source_backfill_batch_invalid');
        }
    }
}
