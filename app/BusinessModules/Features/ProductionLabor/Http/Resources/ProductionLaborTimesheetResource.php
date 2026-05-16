<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProductionLabor\Http\Resources;

use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborTimesheet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductionLaborTimesheet */
final class ProductionLaborTimesheetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProductionLaborTimesheet $timesheet */
        $timesheet = $this->resource;

        return [
            'id' => $timesheet->id,
            'work_order_id' => $timesheet->work_order_id,
            'project_id' => $timesheet->project_id,
            'shift_date' => $timesheet->shift_date?->toDateString(),
            'status' => $timesheet->status,
            'status_label' => trans_message("production_labor.timesheet_statuses.{$timesheet->status}"),
            'workflow_summary' => [
                'stage' => $timesheet->status,
                'status' => $timesheet->status,
                'stage_label' => trans_message("production_labor.timesheet_statuses.{$timesheet->status}"),
                'available_actions' => [],
                'blockers' => [],
                'warnings' => [],
            ],
            'problem_flags' => [],
            'available_actions' => [],
            'entries' => $this->whenLoaded('entries', fn () => $timesheet->entries->map(fn ($entry) => [
                'id' => $entry->id,
                'work_order_line_id' => $entry->work_order_line_id,
                'user_id' => $entry->user_id,
                'employee_id' => $entry->employee_id,
                'include_in_payroll' => (bool) $entry->include_in_payroll,
                'worker_name' => $entry->worker_name,
                'hours' => (float) $entry->hours,
                'safety_permit_reference' => $entry->safety_permit_reference,
                'employee' => $entry->relationLoaded('employee') && $entry->employee ? [
                    'id' => $entry->employee->id,
                    'personnel_number' => $entry->employee->personnel_number,
                    'full_name' => $entry->employee->full_name,
                    'employment_status' => $entry->employee->employment_status,
                ] : null,
            ])->values()),
            'created_at' => $timesheet->created_at?->toIso8601String(),
        ];
    }
}
