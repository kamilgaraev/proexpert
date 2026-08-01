<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use InvalidArgumentException;

final readonly class ReportRowsWindow
{
    public function __construct(
        public ?string $cursor,
        public int $limit,
        public ReportWindowSort $sort,
    ) {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('report_rows_window_limit_invalid');
        }
    }
}
