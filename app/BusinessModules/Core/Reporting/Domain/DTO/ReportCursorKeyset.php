<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use InvalidArgumentException;

final readonly class ReportCursorKeyset
{
    private const MAX_ROW_KEY_BYTES = 256;

    public function __construct(
        public string|int|float|bool|null $lastSortValue,
        public string $lastStableRowKey,
    ) {
        if ((is_float($lastSortValue) && !is_finite($lastSortValue))
            || $lastStableRowKey === ''
            || $lastStableRowKey !== trim($lastStableRowKey)
            || strlen($lastStableRowKey) > self::MAX_ROW_KEY_BYTES) {
            throw new InvalidArgumentException('report_cursor_keyset_invalid');
        }
    }
}
