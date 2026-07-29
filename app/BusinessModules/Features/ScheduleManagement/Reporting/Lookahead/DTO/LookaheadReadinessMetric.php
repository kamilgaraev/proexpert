<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO;

final readonly class LookaheadReadinessMetric
{
    public function __construct(
        public int $taskId,
        public bool $eligible,
        public bool $ready,
        public array $blockingConstraintIds,
        public int $hardBlockers,
        public int $softBlockers,
        public ?string $warningCode,
        public int $maxConstraintAgeDays,
    ) {
    }
}
