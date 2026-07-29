<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\DTO;

use DateTimeImmutable;

final readonly class PayrollCalculationVersion
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public int $payrollPeriodId,
        public int $version,
        public string $status,
        public string $sourceHash,
        public string $formulaVersion,
        public int $sourceRowCount,
        public int $blockingCount,
        public int $warningCount,
        public ?DateTimeImmutable $validatedAt,
        public ?DateTimeImmutable $lockedAt,
    ) {
    }

    public function toArray(): array
    {
        return [
            'calculation_version_id' => $this->id,
            'payroll_period_id' => $this->payrollPeriodId,
            'version' => $this->version,
            'status' => $this->status,
            'source_hash' => $this->sourceHash,
            'formula_version' => $this->formulaVersion,
            'source_rows' => $this->sourceRowCount,
            'blocking_issues' => $this->blockingCount,
            'warnings' => $this->warningCount,
            'validated_at' => $this->validatedAt?->format(DATE_ATOM),
            'locked_at' => $this->lockedAt?->format(DATE_ATOM),
        ];
    }
}
