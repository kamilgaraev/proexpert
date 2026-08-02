<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio\DTO;

use InvalidArgumentException;

final readonly class ValidatedPortfolioDrillDownCell
{
    public function __construct(
        public string $rowKey,
        public string $columnId,
    ) {
        if (trim($rowKey) === ''
            || strlen($rowKey) > 256
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $columnId) !== 1) {
            throw new InvalidArgumentException('portfolio_drill_down_cell_invalid');
        }
    }
}
