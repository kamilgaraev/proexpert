<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO;

final readonly class LookaheadReadinessCoverage
{
    public function __construct(
        public string $numerator,
        public string $denominator,
        public ?string $ratio,
        public int $hardBlockers,
        public int $softBlockers,
    ) {
    }
}
