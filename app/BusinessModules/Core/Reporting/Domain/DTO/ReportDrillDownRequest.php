<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use InvalidArgumentException;

final readonly class ReportDrillDownRequest
{
    public function __construct(
        public string $token,
        public ?string $cursor,
        public int $limit,
    ) {
        if (trim($token) === '' || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('report_drill_down_request_invalid');
        }
    }
}
