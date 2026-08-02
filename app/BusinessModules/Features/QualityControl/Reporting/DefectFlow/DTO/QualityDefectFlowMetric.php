<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO;

use InvalidArgumentException;

final readonly class QualityDefectFlowMetric
{
    public function __construct(
        public int $opening,
        public int $created,
        public int $reopened,
        public int $closed,
        public int $closing,
        public int $overdueCount,
        public ?int $cycleDays,
        public bool $cohortEligible,
    ) {
        if (min($opening, $created, $reopened, $closed, $closing, $overdueCount) < 0
            || $closing !== $opening + $created + $reopened - $closed
            || ($cycleDays !== null && $cycleDays < 0)) {
            throw new InvalidArgumentException('quality_defect_flow_metric_invalid');
        }
    }
}
