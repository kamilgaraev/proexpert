<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Reporting\DTO;

final readonly class ProjectLaborCostRow
{
    public function __construct(
        public string $rowKey,
        public int $timeEntryId,
        public string $workDate,
        public int $employeeId,
        public string $employeeName,
        public int $projectId,
        public string $projectName,
        public ?int $taskId,
        public ?string $taskName,
        public ?int $workTypeId,
        public ?string $workTypeName,
        public bool $billable,
        public ProjectLaborCostMetrics $metrics,
        public array $sourceRefs,
    ) {
    }

    public function toArray(bool $canViewSensitive): array
    {
        $row = [
            'row_key' => $this->rowKey,
            'time_entry_id' => $this->timeEntryId,
            'work_date' => $this->workDate,
            'employee_id' => $this->employeeId,
            'employee_name' => $this->employeeName,
            'project_id' => $this->projectId,
            'project_name' => $this->projectName,
            'task_id' => $this->taskId,
            'task_name' => $this->taskName,
            'work_type_id' => $this->workTypeId,
            'work_type_name' => $this->workTypeName,
            'billable' => $this->billable,
            'hours' => $this->metrics->approvedHours,
            'billable_hours' => $this->metrics->billableHours,
            'billable_percent' => $this->metrics->billablePercent,
            'source_refs' => $this->sourceRefs,
            'quality_warnings' => $this->metrics->qualityWarnings,
        ];

        if ($canViewSensitive) {
            $row += [
                'rate' => $this->metrics->rate,
                'cost' => $this->metrics->cost,
                'currency' => $this->metrics->currency,
                'variance' => $this->metrics->hoursVariance,
                'cost_per_accepted_unit' => $this->metrics->costPerAcceptedUnit,
            ];
        }

        return $row;
    }
}
