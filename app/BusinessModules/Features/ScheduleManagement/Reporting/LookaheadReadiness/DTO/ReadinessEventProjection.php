<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO;

final readonly class ReadinessEventProjection
{
    public function __construct(
        public array $componentsByCategory,
        public array $consumedEventIds,
        public array $blockerEventIds,
        public array $waiverEventIds,
        public string $sourceWatermark,
    ) {}
}
