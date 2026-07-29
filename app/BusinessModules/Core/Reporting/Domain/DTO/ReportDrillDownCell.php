<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use InvalidArgumentException;

final readonly class ReportDrillDownCell
{
    private const MAX_ROW_KEY_BYTES = 256;

    public function __construct(
        public string $rowKey,
        public string $columnId,
    ) {
        if ($rowKey === ''
            || $rowKey !== trim($rowKey)
            || strlen($rowKey) > self::MAX_ROW_KEY_BYTES
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $columnId) !== 1) {
            throw new InvalidArgumentException('report_drill_down_cell_invalid');
        }
    }
}
