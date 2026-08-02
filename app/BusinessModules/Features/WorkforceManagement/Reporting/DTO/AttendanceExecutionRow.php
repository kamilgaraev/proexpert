<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\DTO;

final readonly class AttendanceExecutionRow
{
    public function __construct(
        public string $rowKey,
        public string $workDate,
        public int $employeeId,
        public string $employeeName,
        public ?int $projectId,
        public ?string $projectName,
        public ?int $siteId,
        public ?string $siteName,
        public ?int $shiftId,
        public ?string $shift,
        public string $status,
        public AttendanceExecutionMetrics $metrics,
        public array $sourceRefs,
        public array $auditRefs,
    ) {
    }

    public function toArray(bool $canViewAudit): array
    {
        $row = [
            'row_key' => $this->rowKey,
            'work_date' => $this->workDate,
            'employee_id' => $this->employeeId,
            'employee_name' => $this->employeeName,
            'project_id' => $this->projectId,
            'project_name' => $this->projectName,
            'site_id' => $this->siteId,
            'site_name' => $this->siteName,
            'shift_id' => $this->shiftId,
            'shift' => $this->shift,
            'status' => $this->status,
            'eligible_hours' => $this->metrics->eligibleHours,
            'present_hours' => $this->metrics->presentHours,
            'approved_absence_hours' => $this->metrics->approvedAbsenceHours,
            'absence_hours' => $this->metrics->unexplainedAbsenceHours,
            'overtime_hours' => $this->metrics->overtimeHours,
            'late_hours' => $this->metrics->lateHours,
            'early_hours' => $this->metrics->earlyHours,
            'execution_percent' => $this->metrics->executionPercent,
            'correction_rate' => $this->metrics->correctionRate,
            'violation' => $this->metrics->violation,
            'source_refs' => $this->sourceRefs,
        ];

        if ($canViewAudit) {
            $row['audit_refs'] = $this->auditRefs;
        }

        return $row;
    }
}
