<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\DTO;

final readonly class PayrollReadinessRow
{
    public function __construct(
        public string $rowKey,
        public int $payrollPeriodId,
        public string $periodStart,
        public string $periodEnd,
        public int $calculationVersionId,
        public int $calculationVersion,
        public int $employeeId,
        public string $employeeName,
        public ?int $projectId,
        public ?string $projectName,
        public string $sourceType,
        public int $sourceRowId,
        public string $hours,
        public string $amount,
        public ?string $currency,
        public ?int $issueId,
        public ?string $issueCode,
        public ?string $severity,
        public string $status,
        public array $sourceRefs,
        public array $auditRefs,
    ) {
    }

    public function toArray(bool $canViewAudit): array
    {
        $row = [
            'row_key' => $this->rowKey,
            'payroll_period_id' => $this->payrollPeriodId,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'calculation_version' => $this->calculationVersion,
            'employee_id' => $this->employeeId,
            'employee_name' => $this->employeeName,
            'project_id' => $this->projectId,
            'project_name' => $this->projectName,
            'source_type' => $this->sourceType,
            'source_row_id' => $this->sourceRowId,
            'hours' => $this->hours,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'issue_code' => $this->issueCode,
            'severity' => $this->severity,
            'status' => $this->status,
            'source_refs' => $this->sourceRefs,
        ];

        if ($canViewAudit) {
            $row['audit_refs'] = $this->auditRefs;
            $row['calculation_version_id'] = $this->calculationVersionId;
            $row['issue_id'] = $this->issueId;
        }

        return $row;
    }
}
