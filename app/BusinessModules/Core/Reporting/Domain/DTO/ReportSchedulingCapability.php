<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use InvalidArgumentException;

final readonly class ReportSchedulingCapability
{
    public function __construct(
        public string $code,
        public bool $supportsSubscriptions,
        public bool $reproducibleScheduledSnapshot,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $code) !== 1
            || ($supportsSubscriptions && ! $reproducibleScheduledSnapshot)) {
            throw new InvalidArgumentException('report_scheduling_capability_invalid');
        }
    }
}
