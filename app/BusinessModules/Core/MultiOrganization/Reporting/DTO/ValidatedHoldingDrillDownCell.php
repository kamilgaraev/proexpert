<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\DTO;

use InvalidArgumentException;

final readonly class ValidatedHoldingDrillDownCell
{
    public function __construct(
        public string $rowKey,
        public string $columnId,
    ) {
        if (trim($rowKey) === ''
            || strlen($rowKey) > 256
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $columnId) !== 1) {
            throw new InvalidArgumentException('holding_drill_down_cell_invalid');
        }
    }
}
