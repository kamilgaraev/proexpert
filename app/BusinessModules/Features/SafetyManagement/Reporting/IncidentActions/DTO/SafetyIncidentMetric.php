<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\DTO;

use InvalidArgumentException;

final readonly class SafetyIncidentMetric
{
    public function __construct(
        public int $incidentCount,
        public int $violationCount,
        public int $actionDueCount,
        public int $actionOverdueCount,
        public int $actionClosedOnTimeCount,
        public ?string $frequency,
        public bool $exposureComplete,
    ) {
        if (min($incidentCount, $violationCount, $actionDueCount, $actionOverdueCount, $actionClosedOnTimeCount) < 0
            || $actionOverdueCount > $actionDueCount
            || $actionClosedOnTimeCount > $actionDueCount
            || (! $exposureComplete && $frequency !== null)) {
            throw new InvalidArgumentException('safety_incident_metric_invalid');
        }
    }
}
