<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Enums\ReadinessState;

final readonly class ReadinessEvaluation
{
    public function __construct(
        public ReadinessState $state,
        public array $componentOutcomes,
        public array $reasonCodes,
    ) {}
}
