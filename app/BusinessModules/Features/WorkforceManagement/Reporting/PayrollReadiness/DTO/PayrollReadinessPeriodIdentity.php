<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO;

use InvalidArgumentException;

final readonly class PayrollReadinessPeriodIdentity
{
    public function __construct(
        public int $organizationId,
        public int $periodId,
        public ?int $projectId,
        public string $periodStart,
        public string $periodEnd,
    ) {
        if ($this->organizationId < 1 || $this->periodId < 1 || ($this->projectId !== null && $this->projectId < 1)) {
            throw new InvalidArgumentException('payroll_readiness_period_identity_invalid');
        }

        if ($this->periodStart > $this->periodEnd) {
            throw new InvalidArgumentException('payroll_readiness_period_range_invalid');
        }
    }

    public static function fromRecord(int $organizationId, object $period): self
    {
        return new self(
            organizationId: $organizationId,
            periodId: (int) $period->id,
            projectId: $period->project_id !== null ? (int) $period->project_id : null,
            periodStart: (string) $period->period_start,
            periodEnd: (string) $period->period_end,
        );
    }
}
