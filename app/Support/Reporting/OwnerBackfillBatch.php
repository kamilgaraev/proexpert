<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use InvalidArgumentException;

final readonly class OwnerBackfillBatch
{
    public function __construct(
        public int $scanned,
        public int $projected,
        public int $gapCount,
        public int|string $nextCursor,
        public bool $done,
        public string $inputHash,
        public string $outputHash,
    ) {
        if ($scanned < 0
            || $projected < 0
            || $gapCount < 0
            || (is_int($nextCursor) && $nextCursor < 0)
            || (is_string($nextCursor) && strlen($nextCursor) > 512)
            || preg_match('/^[a-f0-9]{64}$/D', $inputHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $outputHash) !== 1) {
            throw new InvalidArgumentException('owner_backfill_batch_invalid');
        }
    }
}
