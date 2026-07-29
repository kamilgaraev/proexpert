<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use InvalidArgumentException;

final readonly class ReportDrillDownInput
{
    public function __construct(
        public ReportDrillDownCell $cell,
        public ?string $cursor,
        public int $limit,
    ) {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('report_drill_down_input_invalid');
        }
    }
}
