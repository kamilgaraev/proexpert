<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use InvalidArgumentException;

final readonly class ReportWindowSort
{
    public function __construct(
        public string $field,
        public ReportSortDirection $direction,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field) !== 1) {
            throw new InvalidArgumentException('report_window_sort_field_invalid');
        }
    }
}
