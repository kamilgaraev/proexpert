<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use InvalidArgumentException;

final readonly class ReportSourceBackfillBatch
{
    public function __construct(
        public ReportSourceBackfillCursor $from,
        public ReportSourceBackfillCursor $to,
        public array $sourceKeys,
        public bool $final,
        public string $inputHash,
    ) {
        if (
            ! array_is_list($sourceKeys)
            || preg_match('/^[a-f0-9]{64}$/D', $inputHash) !== 1
        ) {
            throw new InvalidArgumentException('report_source_backfill_batch_invalid');
        }
    }
}
