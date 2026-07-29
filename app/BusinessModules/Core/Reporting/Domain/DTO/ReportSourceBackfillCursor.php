<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use InvalidArgumentException;

final readonly class ReportSourceBackfillCursor
{
    public function __construct(
        public int $lastSourceId,
        public string $sourceWatermark,
    ) {
        if ($lastSourceId < 0
            || preg_match('/^[a-z][a-z0-9_-]{0,31}:[A-Za-z0-9._-]{1,160}$/D', $sourceWatermark) !== 1
        ) {
            throw new InvalidArgumentException('report_source_backfill_cursor_invalid');
        }
    }

    public function canonicalIdentity(): array
    {
        return [
            'last_source_id' => $this->lastSourceId,
            'source_watermark' => $this->sourceWatermark,
        ];
    }
}
