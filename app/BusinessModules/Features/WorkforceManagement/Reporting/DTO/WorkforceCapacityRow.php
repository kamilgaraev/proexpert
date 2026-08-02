<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\DTO;

final readonly class WorkforceCapacityRow
{
    public function __construct(
        public string $rowKey,
        public string $month,
        public int $staffUnitId,
        public int $departmentId,
        public string $departmentName,
        public int $positionId,
        public string $positionName,
        public ?int $projectId,
        public ?string $projectName,
        public WorkforceCapacityMetrics $metrics,
        public array $sourceRefs,
    ) {
    }

    public function toArray(): array
    {
        return [
            'row_key' => $this->rowKey,
            'month' => $this->month,
            'staff_unit_id' => $this->staffUnitId,
            'department_id' => $this->departmentId,
            'department_name' => $this->departmentName,
            'position_id' => $this->positionId,
            'position_name' => $this->positionName,
            'project_id' => $this->projectId,
            'project_name' => $this->projectName,
            'planned_fte' => $this->metrics->approvedFte,
            'assigned_fte' => $this->metrics->assignedFte,
            'vacancy_fte' => $this->metrics->vacancyFte,
            'overstaffing_fte' => $this->metrics->overstaffingFte,
            'vacancy_percent' => $this->metrics->vacancyPercent,
            'planned_capacity_hours' => $this->metrics->plannedCapacityHours,
            'capacity_hours' => $this->metrics->assignedCapacityHours,
            'rate_type' => $this->metrics->rateType,
            'rate' => $this->metrics->rate,
            'currency' => $this->metrics->currency,
            'period_cost_run_rate' => $this->metrics->periodCostRunRate,
            'quality_warnings' => $this->metrics->qualityWarnings,
            'source_refs' => $this->sourceRefs,
        ];
    }
}
